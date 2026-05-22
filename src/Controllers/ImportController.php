<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
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

        $rows = $this->parseFile($info['file'], $info['ext']);
        $headers = array_shift($rows);
        $headerIdx = array_flip($headers);

        $addrCol = $mapping['address_column'] ?? null;
        $nameCol = $mapping['name_column'] ?? null;
        $latCol = $mapping['lat_column'] ?? null;
        $lngCol = $mapping['lng_column'] ?? null;
        $customCols = $mapping['custom_columns'] ?? [];

        $batchId = Database::uuid();
        $svc = new GoogleMapsService();
        $geocodedCount = 0;
        $failures = [];

        foreach ($rows as $i => $row) {
            $rowAssoc = [];
            foreach ($headers as $j => $h) $rowAssoc[$h] = $row[$j] ?? null;

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
                    $failures[] = ['row' => $i + 2, 'address' => $address, 'error' => $e->getMessage()];
                    continue;
                }
            } else {
                $failures[] = ['row' => $i + 2, 'address' => null, 'error' => 'No address or coordinates'];
                continue;
            }

            $custom = [];
            foreach ($customCols as $c) {
                if (isset($rowAssoc[$c])) $custom[$c] = $rowAssoc[$c];
            }

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
            } catch (\Throwable $e) {
                $failures[] = ['row' => $i + 2, 'address' => $address, 'error' => $e->getMessage()];
            }
        }

        @unlink($info['file']);
        CacheService::delete('import:' . $token);

        Response::success([
            'batch_id' => $batchId,
            'total_rows' => count($rows),
            'geocoded_count' => $geocodedCount,
            'imported' => count($rows) - count($failures),
            'failed_count' => count($failures),
            'failures' => $failures,
        ]);
    }

    public function status(Request $request): void
    {
        $batchId = $request->getParam('batchId');
        $count = ImportedPoint::countByBatch($batchId);
        Response::success(['batch_id' => $batchId, 'point_count' => $count]);
    }

    public function deleteImport(Request $request): void
    {
        $batchId = $request->getParam('batchId');
        $deleted = ImportedPoint::deleteBatch($batchId);
        Response::success(['batch_id' => $batchId, 'deleted' => $deleted]);
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
