<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Config;
use App\Core\PlanLimits;
use App\Models\Area;
use App\Models\Project;
use App\Models\Report;
use App\Models\POICache;
use App\Services\CensusService;
use App\Services\GeoUtils;
use TCPDF;

class ReportController
{
    public function generate(Request $request): void
    {
        // Plan check: PDF reports are paid only.
        $plan = $request->user['plan'] ?? 'free';
        if (!PlanLimits::getLimit($plan, 'reports')) {
            Response::error('PDF reports require a paid plan. Upgrade to enable.', 403);
        }
        $area = Area::findById($request->getParam('id'));
        if (!$area) Response::error('Area not found', 404);
        $project = Project::findById($area['project_id']);
        if (!$project || $project['organization_id'] !== $request->user['organization_id']) {
            Response::error('Access denied', 403);
        }
        $body = $request->getBody() ?? [];
        $type = $body['report_type'] ?? 'area_analysis';
        $title = $body['title'] ?? ($area['name'] . ' — Area Analysis');

        $demo = (new CensusService())->getDemographicsForArea($area['id']);
        $poiCache = POICache::get(md5('area:' . $area['id']));
        $pois = $poiCache['results'] ?? [];

        $mapUrl = $this->buildStaticMapUrl($area);
        $mapPath = $this->downloadStaticMap($mapUrl, $area['id']);

        ob_start();
        $reportData = [
            'area' => $area,
            'demographics' => $demo,
            'pois' => $pois,
            'map_path' => $mapPath,
            'title' => $title,
            'generated_at' => date('M j, Y g:i A'),
        ];
        extract($reportData);
        include dirname(__DIR__) . '/Templates/report_area_analysis.php';
        $html = ob_get_clean();

        $pdfPath = $this->htmlToPdf($html, $area['id']);

        $reportId = Report::create([
            'area_id' => $area['id'],
            'project_id' => $project['id'],
            'report_type' => $type,
            'title' => $title,
            'file_path' => $pdfPath,
            'generated_by' => $request->user['id'],
        ]);

        Response::success([
            'report_id' => $reportId,
            'download_url' => '/api/reports/' . $reportId . '/download',
        ], 'Report generated', 201);
    }

    public function download(Request $request): void
    {
        $id = $request->getParam('id');
        $report = Report::findById($id);
        if (!$report) Response::error('Report not found', 404);
        $project = Project::findById($report['project_id']);
        if (!$project || $project['organization_id'] !== $request->user['organization_id']) {
            Response::error('Access denied', 403);
        }
        if (!file_exists($report['file_path'])) Response::error('Report file missing', 404);
        Response::file($report['file_path'], basename($report['file_path']), 'application/pdf');
    }

    public function list(Request $request): void
    {
        $projectId = $request->getQuery('project_id');
        if (!$projectId) Response::error('project_id required');
        $project = Project::findById($projectId);
        if (!$project || $project['organization_id'] !== $request->user['organization_id']) {
            Response::error('Access denied', 403);
        }
        $reports = Report::getByProject($projectId);
        $out = array_map(fn($r) => [
            'id' => $r['id'],
            'title' => $r['title'],
            'type' => $r['report_type'],
            'area_name' => $r['area_name'] ?? null,
            'generated_at' => $r['generated_at'],
            'download_url' => '/api/reports/' . $r['id'] . '/download',
        ], $reports);
        Response::success($out);
    }

    private function buildStaticMapUrl(array $area): string
    {
        $key = Config::get('GOOGLE_API_KEY', '');
        $ring = $area['geometry']['coordinates'][0] ?? [];
        if (empty($ring)) return '';
        // simplify if too many points
        if (count($ring) > 80) {
            $step = (int)ceil(count($ring) / 80);
            $simplified = [];
            for ($i = 0; $i < count($ring); $i += $step) $simplified[] = $ring[$i];
            $simplified[] = end($ring);
            $ring = $simplified;
        }
        $encoded = GeoUtils::encodePath($ring);
        $path = 'fillcolor:0x6B4EFF33|color:0x6B4EFFFF|weight:2|enc:' . $encoded;
        return 'https://maps.googleapis.com/maps/api/staticmap?size=800x500&maptype=roadmap&path='
             . urlencode($path) . '&key=' . $key;
    }

    private function downloadStaticMap(string $url, string $areaId): ?string
    {
        if (!$url) return null;
        $dir = dirname(__DIR__, 2) . '/storage/reports/maps';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $path = $dir . '/' . $areaId . '.png';
        $img = @file_get_contents($url);
        if ($img === false) return null;
        file_put_contents($path, $img);
        return $path;
    }

    private function htmlToPdf(string $html, string $areaId): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/reports';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $outPath = $dir . '/report-' . $areaId . '-' . date('Ymd-His') . '.pdf';

        // Try wkhtmltopdf first
        $wkBin = trim((string)@shell_exec('which wkhtmltopdf'));
        if ($wkBin && is_executable($wkBin)) {
            $tmpHtml = tempnam(sys_get_temp_dir(), 'rpt') . '.html';
            file_put_contents($tmpHtml, $html);
            exec(escapeshellcmd($wkBin) . ' --enable-local-file-access '
                 . escapeshellarg($tmpHtml) . ' ' . escapeshellarg($outPath) . ' 2>&1', $out, $code);
            @unlink($tmpHtml);
            if ($code === 0 && file_exists($outPath)) return $outPath;
        }

        // Fallback: TCPDF
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator('Smappen');
        $pdf->SetTitle('Area Analysis');
        $pdf->SetMargins(15, 15, 15);
        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output($outPath, 'F');
        return $outPath;
    }
}
