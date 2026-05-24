<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\Project;
use App\Models\Area;
use App\Models\ImportedPoint;
use App\Models\POICache;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

class ExportController
{
    public function exportAreas(Request $request): void
    {
        $project = $this->verifyProject($request);
        $format = $request->getQuery('format', 'csv');
        $areas = Area::getByProject($project['id']);

        if ($format === 'geojson') {
            $features = array_map(fn($a) => [
                'type' => 'Feature',
                'id' => $a['id'],
                'geometry' => $a['geometry'] ?? null,
                'properties' => [
                    'name' => $a['name'], 'area_type' => $a['area_type'],
                    'travel_mode' => $a['travel_mode'], 'travel_time_minutes' => $a['travel_time_minutes'],
                ],
            ], $areas);
            $path = $this->writeFile('geojson', json_encode(['type' => 'FeatureCollection', 'features' => $features], JSON_PRETTY_PRINT));
            Response::success(['download_url' => $this->downloadUrl($path)]);
            return;
        }
        if ($format === 'kml') {
            $kml = $this->areasToKml($areas);
            $path = $this->writeFile('kml', $kml);
            Response::success(['download_url' => $this->downloadUrl($path)]);
            return;
        }

        $headers = ['name', 'type', 'center_address', 'center_lat', 'center_lng', 'travel_mode', 'travel_time_minutes', 'travel_distance_km', 'population', 'median_income'];
        $rows = [];
        foreach ($areas as $a) {
            $demo = $a['demographics_cache'] ?? [];
            $rows[] = [
                $a['name'], $a['area_type'], $a['center_address'], $a['center_lat'], $a['center_lng'],
                $a['travel_mode'], $a['travel_time_minutes'], $a['travel_distance_km'],
                $demo['population']['total'] ?? '',
                $demo['income']['median_household'] ?? '',
            ];
        }
        $path = $format === 'xlsx' ? $this->generateXlsx($headers, $rows, 'Areas') : $this->generateCsv($headers, $rows);
        Response::success(['download_url' => $this->downloadUrl($path)]);
    }

    public function exportPOIs(Request $request): void
    {
        $areaId = $request->getParam('areaId');
        $area = Area::findById($areaId);
        if (!$area) Response::error('Area not found', 404);
        $project = Project::findById($area['project_id']);
        if (!$project || $project['organization_id'] !== $request->user['organization_id']) {
            Response::error('Access denied', 403);
        }
        $format = $request->getQuery('format', 'csv');
        $cached = POICache::get(md5('area:' . $areaId));
        $places = $cached['results'] ?? [];

        $headers = ['name', 'address', 'phone', 'website', 'rating', 'review_count', 'types', 'lat', 'lng'];
        $rows = [];
        foreach ($places as $p) {
            $rows[] = [
                $p['displayName']['text'] ?? '',
                $p['formattedAddress'] ?? '',
                $p['nationalPhoneNumber'] ?? '',
                $p['websiteUri'] ?? '',
                $p['rating'] ?? '',
                $p['userRatingCount'] ?? '',
                implode(',', $p['types'] ?? []),
                $p['location']['latitude'] ?? '',
                $p['location']['longitude'] ?? '',
            ];
        }
        $path = $format === 'xlsx' ? $this->generateXlsx($headers, $rows, 'POIs') : $this->generateCsv($headers, $rows);
        Response::success(['download_url' => $this->downloadUrl($path)]);
    }

    public function exportImportedPoints(Request $request): void
    {
        $project = $this->verifyProject($request);
        $format = $request->getQuery('format', 'csv');
        $batchId = $request->getQuery('batch_id');
        if ($batchId) {
            // Make sure the batch belongs to this project, otherwise anyone could
            // export points from any other org's import by guessing a UUID.
            $batchProjectId = ImportedPoint::projectIdForBatch($batchId);
            if ($batchProjectId !== $project['id']) {
                Response::error('Batch not found in this project', 404);
            }
        }
        $points = $batchId ? ImportedPoint::getByBatch($batchId) : ImportedPoint::getByProject($project['id']);

        $customKeys = [];
        foreach ($points as $p) {
            $cd = is_string($p['custom_data'] ?? null) ? json_decode($p['custom_data'], true) : ($p['custom_data'] ?? []);
            foreach ((array)$cd as $k => $v) $customKeys[$k] = true;
        }
        $customKeys = array_keys($customKeys);

        $headers = array_merge(['label', 'address', 'lat', 'lng'], $customKeys);
        $rows = [];
        foreach ($points as $p) {
            $cd = is_string($p['custom_data'] ?? null) ? json_decode($p['custom_data'], true) : ($p['custom_data'] ?? []);
            $row = [$p['label'], $p['address'], $p['lat'], $p['lng']];
            foreach ($customKeys as $k) $row[] = $cd[$k] ?? '';
            $rows[] = $row;
        }
        $path = $format === 'xlsx' ? $this->generateXlsx($headers, $rows, 'Points') : $this->generateCsv($headers, $rows);
        Response::success(['download_url' => $this->downloadUrl($path)]);
    }

    public function download(Request $request): void
    {
        $filename = basename($request->getParam('filename'));
        $path = dirname(__DIR__, 2) . '/storage/exports/' . $filename;
        if (!file_exists($path)) Response::error('File not found', 404);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'csv' => 'text/csv',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'geojson' => 'application/geo+json',
            'kml' => 'application/vnd.google-earth.kml+xml',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
        Response::file($path, $filename, $mime);
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

    private function generateCsv(array $headers, array $rows): string
    {
        $path = $this->newExportPath('csv');
        $f = fopen($path, 'w');
        fputcsv($f, $headers);
        foreach ($rows as $r) fputcsv($f, $r);
        fclose($f);
        return $path;
    }

    private function generateXlsx(array $headers, array $rows, string $sheetName = 'Sheet'): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($sheetName);
        $sheet->fromArray([$headers], null, 'A1');
        if (!empty($rows)) $sheet->fromArray($rows, null, 'A2');
        $path = $this->newExportPath('xlsx');
        $writer = new XlsxWriter($spreadsheet);
        $writer->save($path);
        return $path;
    }

    private function areasToKml(array $areas): string
    {
        // Build a Style per unique fill color so Google Earth renders the
        // areas with the same color the user sees in Smappen. KML colors are
        // AABBGGRR (note: alpha first, then BLUE-GREEN-RED — not RGBA).
        $styles = [];
        foreach ($areas as $a) {
            $hex = ltrim($a['fill_color'] ?? '#7848BB', '#');
            if (strlen($hex) !== 6) $hex = '7848BB';
            $styles[$hex] = true;
        }
        $styleXml = '';
        foreach (array_keys($styles) as $hex) {
            $r = substr($hex, 0, 2);
            $g = substr($hex, 2, 2);
            $b = substr($hex, 4, 2);
            $fillKml = '99' . $b . $g . $r;   // 99 ≈ 60% alpha
            $lineKml = 'FF' . $b . $g . $r;   // opaque outline
            $styleXml .= '<Style id="s_' . $hex . '">'
                . '<LineStyle><color>' . $lineKml . '</color><width>2</width></LineStyle>'
                . '<PolyStyle><color>' . $fillKml . '</color><fill>1</fill><outline>1</outline></PolyStyle>'
                . '</Style>';
        }

        $kml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $kml .= '<kml xmlns="http://www.opengis.net/kml/2.2"><Document>';
        $kml .= '<name>Smappen Areas</name>';
        $kml .= $styleXml;
        foreach ($areas as $a) {
            $hex = strtoupper(ltrim($a['fill_color'] ?? '#7848BB', '#'));
            if (strlen($hex) !== 6) $hex = '7848BB';
            $desc = '';
            $dc = $a['demographics_cache'] ?? [];
            if (is_array($dc)) {
                $pop = $dc['population']['total'] ?? $dc['population'] ?? null;
                $inc = $dc['income']['median_household'] ?? $dc['median_household_income'] ?? null;
                if ($pop || $inc) {
                    $desc = '<description><![CDATA['
                        . ($pop ? '<b>Population:</b> ' . number_format((int)$pop) . '<br>' : '')
                        . ($inc ? '<b>Median income:</b> $' . number_format((int)$inc) : '')
                        . ']]></description>';
                }
            }
            $kml .= '<Placemark>'
                . '<name>' . htmlspecialchars($a['name']) . '</name>'
                . $desc
                . '<styleUrl>#s_' . $hex . '</styleUrl>';
            if (!empty($a['geometry']['coordinates'][0])) {
                $coords = [];
                foreach ($a['geometry']['coordinates'][0] as $p) {
                    $coords[] = $p[0] . ',' . $p[1] . ',0';
                }
                $kml .= '<Polygon><outerBoundaryIs><LinearRing><coordinates>'
                     . implode(' ', $coords) . '</coordinates></LinearRing></outerBoundaryIs></Polygon>';
            }
            $kml .= '</Placemark>';
        }
        $kml .= '</Document></kml>';
        return $kml;
    }

    private function writeFile(string $ext, string $content): string
    {
        $path = $this->newExportPath($ext);
        file_put_contents($path, $content);
        return $path;
    }

    private function newExportPath(string $ext): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/exports';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $name = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        return $dir . '/' . $name;
    }

    private function downloadUrl(string $path): string
    {
        $filename = basename($path);
        return '/api/exports/' . $filename;
    }
}
