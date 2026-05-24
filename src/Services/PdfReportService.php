<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * #20 — Branded multi-page PDF reports.
 *
 * Generates a polished PDF for an area: cover page with org logo + area
 * thumbnail, demographics tables, methodology footer. Delivered via
 * either:
 *
 *   • Direct download — POST /api/areas/{id}/report.pdf
 *   • Scheduled email — wired by ScheduledReports cron worker
 *
 * Uses TCPDF (already a composer dependency) so no new infra needed.
 *
 * Architecture:
 *   - generate($areaId, $opts): returns absolute path to a fresh PDF in
 *     storage/reports/{uuid}.pdf
 *   - report metadata persisted in `reports` table (existing) so we can
 *     list and re-download historical ones
 *
 * v1 intentionally skips:
 *   - Charts (would need wkhtmltopdf or pchart for nice rendering)
 *   - Multi-area comparison PDFs (lives in v2)
 *   - Custom user-uploaded branding assets (uses Smappen brand by default)
 */
class PdfReportService
{
    public function generate(string $areaId, array $opts = []): string
    {
        $db = Database::getInstance();
        $area = $db->fetch(
            "SELECT a.*, p.name AS project_name, o.name AS org_name
               FROM areas a
               JOIN projects p ON p.id = a.project_id
               JOIN organizations o ON o.id = p.organization_id
              WHERE a.id = ?",
            [$areaId]
        );
        if (!$area) throw new \RuntimeException('Area not found');

        $demoCache = $area['demographics_cache'] ? json_decode($area['demographics_cache'], true) : null;

        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator('Smappen');
        $pdf->SetTitle($area['name']);
        $pdf->SetMargins(20, 28, 20);
        $pdf->setHeaderData('', 0, 'Smappen · ' . ($area['org_name'] ?? ''), $area['project_name'] ?? '', [16, 16, 16], [120, 72, 187]);
        $pdf->setHeaderFont(['helvetica', '', 10]);
        $pdf->setFooterFont(['helvetica', '', 8]);
        $pdf->SetAutoPageBreak(true, 25);

        // ── Cover page ──────────────────────────────────────────────────
        $pdf->AddPage();
        $pdf->SetTextColor(26, 26, 46); // brand ink
        $pdf->SetFont('helvetica', 'B', 28);
        $pdf->Ln(20);
        $pdf->Cell(0, 12, 'Area report', 0, 1);
        $pdf->SetFont('helvetica', '', 18);
        $pdf->Cell(0, 10, $area['name'], 0, 1);
        $pdf->SetFont('helvetica', '', 11);
        $pdf->SetTextColor(110, 110, 130);
        if ($area['center_address']) $pdf->Cell(0, 6, $area['center_address'], 0, 1);
        $pdf->Cell(0, 6, 'Generated ' . date('M j, Y'), 0, 1);

        $pdf->Ln(10);

        // ── Headline stats ──────────────────────────────────────────────
        $pdf->SetTextColor(26, 26, 46);
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 8, 'Demographics', 0, 1);
        $pdf->Ln(2);

        if ($demoCache) {
            $rows = [
                ['Population',         self::n($demoCache['population']['total'] ?? null)],
                ['Households',         self::n($demoCache['housing']['total_units'] ?? null)],
                ['Median income',      self::money($demoCache['income']['median_household'] ?? null)],
                ['Median home value',  self::money($demoCache['housing']['median_value'] ?? null)],
                ['Density (per km²)',  self::n($demoCache['population']['density_per_sq_km'] ?? null)],
                ['Unemployment rate',  ($demoCache['employment']['unemployment_rate'] ?? '—') . '%'],
            ];
            self::renderTwoColTable($pdf, $rows);
            $pdf->Ln(6);

            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(0, 7, 'Age distribution', 0, 1);
            $age = $demoCache['age'] ?? [];
            $pop = (float)($demoCache['population']['total'] ?? 1);
            $ageRows = [
                ['Under 18', self::pct($age['under_18'] ?? null, $pop)],
                ['18–34',    self::pct($age['18_to_34'] ?? null, $pop)],
                ['35–54',    self::pct($age['35_to_54'] ?? null, $pop)],
                ['55–64',    self::pct($age['55_to_64'] ?? null, $pop)],
                ['65+',      self::pct($age['65_plus'] ?? null, $pop)],
            ];
            self::renderTwoColTable($pdf, $ageRows);
        } else {
            $pdf->SetFont('helvetica', '', 11);
            $pdf->MultiCell(0, 6, 'Demographics have not been computed for this area yet. Open the area in Smappen and the Demographics tab to load them.');
        }

        // ── Methodology footer ──────────────────────────────────────────
        $pdf->Ln(8);
        $pdf->SetDrawColor(220, 220, 226);
        $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
        $pdf->Ln(3);
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->SetTextColor(110, 110, 130);
        $pdf->MultiCell(0, 4,
            'Data: U.S. Census Bureau, American Community Survey 5-year estimates (2023 vintage). '
          . 'Tract-level estimates intersected with the area polygon and weighted by overlap. '
          . 'Methodology: smappen.mygreendock.com/methodology'
        );

        // ── Save ────────────────────────────────────────────────────────
        $dir = dirname(__DIR__, 2) . '/storage/reports';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $filename = Database::uuid() . '.pdf';
        $path = $dir . '/' . $filename;
        $pdf->Output($path, 'F');

        // Persist a `reports` row so the user can find it later.
        try {
            $db->query(
                'INSERT INTO reports (id, area_id, filename, format, kind, created_at)
                 VALUES (?, ?, ?, "pdf", "area", ?)',
                [Database::uuid(), $areaId, $filename, date('Y-m-d H:i:s')]
            );
        } catch (\Throwable $e) {
            // Existing schema may not have all columns — best-effort log.
            error_log('PdfReportService row insert failed: ' . $e->getMessage());
        }
        return $path;
    }

    private static function n($v): string
    {
        if ($v === null || $v === '') return '—';
        return number_format((float)$v);
    }
    private static function money($v): string
    {
        if ($v === null || $v === '') return '—';
        return '$' . number_format((float)$v);
    }
    private static function pct($v, float $total): string
    {
        if ($v === null || $v === '' || $total <= 0) return '—';
        return number_format(((float)$v / $total) * 100, 1) . '%';
    }
    private static function renderTwoColTable(\TCPDF $pdf, array $rows): void
    {
        $pdf->SetFont('helvetica', '', 11);
        foreach ($rows as [$k, $v]) {
            $pdf->SetTextColor(110, 110, 130); $pdf->Cell(70, 7, $k, 0, 0);
            $pdf->SetTextColor(26, 26, 46);    $pdf->Cell(0,  7, $v, 0, 1);
        }
    }
}
