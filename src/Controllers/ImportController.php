<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Core\PlanLimits;
use App\Models\Project;
use App\Models\ImportedPoint;
use App\Services\GoogleMapsService;
use App\Services\CacheService;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportController
{
    public function upload(Request $request): void
    {
        $project = $this->verifyProject($request);
        $file = $request->getFile('file');
        if (!$file || ($file['error'] ?? 1) !== UPLOAD_ERR_OK) Response::error('No file uploaded');
        $size = (int)($file['size'] ?? 0);
        if ($size > 10 * 1024 * 1024) Response::error('File exceeds 10MB');

        $name = $file['name'];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xlsx'])) Response::error('Only CSV and XLSX supported');

        $uploadDir = dirname(__DIR__, 2) . '/storage/uploads';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
        $token = bin2hex(random_bytes(16));
        $dest = $uploadDir . '/' . $token . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $dest)) Response::error('Upload failed');

        $rows = $this->parseFile($dest, $ext, 11); // header + 10 preview rows
        if (empty($rows)) Response::error('File is empty');
        $headers = array_shift($rows);
        $preview = array_slice($rows, 0, 10);

        $total = count($this->parseFile($dest, $ext, 0)) - 1;

        CacheService::set('import:' . $token, [
            'project_id' => $project['id'],
            'file' => $dest,
            'ext' => $ext,
            'headers' => $headers,
            'total_rows' => $total,
        ], 3600);

        Response::success([
            'import_token' => $token,
            'headers' => $headers,
            'preview' => $preview,
            'total_rows' => $total,
        ]);
    }

    public function configure(Request $request): void
    {
        $project = $this->verifyProject($request);
        $body = $request->getBody() ?? [];
        $token = $body['import_token'] ?? null;
        $mapping = $body['column_mapping'] ?? [];
        if (!$token) Response::error('import_token required');
        $info = CacheService::getJson('import:' . $token);
        if (!$info || $info['project_id'] !== $project['id']) Response::error('Invalid import token', 404);

        // Plan limit: max_import_rows.
        $plan = $request->user['plan'] ?? 'free';
        $maxRows = PlanLimits::getLimit($plan, 'max_import_rows');
        if ($maxRows !== -1 && (int)$info['total_rows'] > (int)$maxRows) {
            Response::error("Import has {$info['total_rows']} rows but $plan plan allows max $maxRows. Upgrade to import more.", 403);
        }

        $addrCol = $mapping['address_column'] ?? null;
        $nameCol = $mapping['name_column'] ?? null;
        $latCol = $mapping['lat_column'] ?? null;
        $lngCol = $mapping['lng_column'] ?? null;
        $customCols = $mapping['custom_columns'] ?? [];

        $batchId = Database::uuid();
        $svc = new GoogleMapsService();
        $geocodedCount = 0;
        $failures = [];
        $imported = 0;
        $total = 0;

        // Stream rows one at a time instead of loading the whole file (#23).
        // For CSV: fgetcsv. For XLSX: PhpSpreadsheet's rowIterator with
        // getRowIterator(2, ...) skipping the header row.
        $processed = function (array $rowAssoc, int $rowNum) use (
            $batchId, $project, $svc, &$geocodedCount, &$failures, &$imported,
            $addrCol, $nameCol, $latCol, $lngCol, $customCols
        ) {
            $lat = null; $lng = null; $address = null;
            if ($latCol && $lngCol && isset($rowAssoc[$latCol], $rowAssoc[$lngCol])) {
                $lat = (float)$rowAssoc[$latCol];
                $lng = (float)$rowAssoc[$lngCol];
            } elseif ($addrCol && isset($rowAssoc[$addrCol])) {
                $address = trim((string)$rowAssoc[$addrCol]);
                try {
                    $geo = $svc->geocode($address);
                    $lat = $geo['lat']; $lng = $geo['lng'];
                    $geocodedCount++;
                } catch (\Throwable $e) {
                    $failures[] = ['row' => $rowNum, 'address' => $address, 'error' => $e->getMessage()];
                    return;
                }
            } else {
                $failures[] = ['row' => $rowNum, 'address' => null, 'error' => 'No address or coordinates'];
                return;
            }
            $custom = [];
            foreach ($customCols as $c) if (isset($rowAssoc[$c])) $custom[$c] = $rowAssoc[$c];
            try {
                ImportedPoint::create([
                    'project_id' => $project['id'],
                    'import_batch_id' => $batchId,
                    'label' => $nameCol ? ($rowAssoc[$nameCol] ?? null) : null,
                    'address' => $address,
                    'lat' => $lat,
                    'lng' => $lng,
                    'custom_data' => $custom,
                ]);
                $imported++;
            } catch (\Throwable $e) {
                $failures[] = ['row' => $rowNum, 'address' => $address, 'error' => $e->getMessage()];
            }
        };

        $headers = $info['headers'];
        if ($info['ext'] === 'csv') {
            $f = fopen($info['file'], 'r');
            if (!$f) Response::error('Cannot read upload', 500);
            // rowNum starts at 0; increment first → header is row 1, first
            // data row is row 2. Earlier draft started at 1 and the failure
            // messages told users "row 3" for their actual second line, which
            // was extremely confusing when 80% of geocoding failed.
            $first = true;
            $rowNum = 0;
            while (($r = fgetcsv($f)) !== false) {
                $rowNum++;
                if ($first) { $first = false; continue; } // skip header row
                $assoc = [];
                foreach ($headers as $j => $h) $assoc[$h] = $r[$j] ?? null;
                $total++;
                $processed($assoc, $rowNum);
            }
            fclose($f);
        } else {
            // XLSX: row-iterator in 500-row chunks so memory stays bounded.
            $spreadsheet = IOFactory::load($info['file']);
            $sheet = $spreadsheet->getActiveSheet();
            $rowIter = $sheet->getRowIterator(2);
            foreach ($rowIter as $rowObj) {
                $rowNum = $rowObj->getRowIndex();
                $cellIter = $rowObj->getCellIterator();
                $cellIter->setIterateOnlyExistingCells(false);
                $assoc = [];
                $j = 0;
                foreach ($cellIter as $cell) {
                    $assoc[$headers[$j] ?? "col$j"] = $cell->getValue();
                    $j++;
                }
                $total++;
                $processed($assoc, $rowNum);
            }
        }

        @unlink($info['file']);
        CacheService::delete('import:' . $token);

        Response::success([
            'batch_id' => $batchId,
            'total_rows' => $total,
            'geocoded_count' => $geocodedCount,
            'imported' => $imported,
            'failed_count' => count($failures),
            'failures' => array_slice($failures, 0, 100), // cap response size
        ]);
    }

    public function status(Request $request): void
    {
        $batchId = $request->getParam('batchId');
        $this->verifyBatchOwnership($request, $batchId);
        $count = ImportedPoint::countByBatch($batchId);
        Response::success(['batch_id' => $batchId, 'point_count' => $count]);
    }

    public function deleteImport(Request $request): void
    {
        $batchId = $request->getParam('batchId');
        $this->verifyBatchOwnership($request, $batchId);
        $deleted = ImportedPoint::deleteBatch($batchId);
        Response::success(['batch_id' => $batchId, 'deleted' => $deleted]);
    }

    private function verifyBatchOwnership(Request $request, string $batchId): void
    {
        $projectId = ImportedPoint::projectIdForBatch($batchId);
        if (!$projectId) Response::error('Batch not found', 404);
        $project = Project::findById($projectId);
        if (!$project || $project['organization_id'] !== $request->user['organization_id']) {
            Response::error('Access denied', 403);
        }
    }

    private function verifyProject(Request $request): array
    {
        $project = Project::findById($request->getParam('projectId'));
        if (!$project) Response::error('Project not found', 404);
        if ($project['organization_id'] !== $request->user['organization_id']) {
            Response::error('Access denied', 403);
        }
        return $project;
    }

    private function parseFile(string $path, string $ext, int $maxRows = 0): array
    {
        $rows = [];
        if ($ext === 'csv') {
            $f = fopen($path, 'r');
            while (($r = fgetcsv($f)) !== false) {
                $rows[] = $r;
                if ($maxRows > 0 && count($rows) >= $maxRows) break;
            }
            fclose($f);
        } else {
            $spreadsheet = IOFactory::load($path);
            $sheet = $spreadsheet->getActiveSheet();
            foreach ($sheet->toArray() as $r) {
                $rows[] = $r;
                if ($maxRows > 0 && count($rows) >= $maxRows) break;
            }
        }
        return $rows;
    }
}
