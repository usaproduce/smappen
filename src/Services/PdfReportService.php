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

    /**
     * Carafe "Money found this month" report — spec §5.10.
     *
     * Cover with the headline ROI number, a list of accepted/measured
     * recommendations with before/after, an overpay-flags section, and
     * a methodology footnote citing the COGS source. The hero figure
     * comes from RoiService::monthlySummary so the PDF can't drift from
     * the war-room number.
     *
     * Branding: Carafe palette + Nunito where TTF is available (falls
     * back to Helvetica when /storage/fonts/Nunito.ttf isn't installed,
     * so deploys without the font asset still produce a clean PDF).
     */
    public function generateMoneyFound(string $restaurantId, array $opts = []): string
    {
        $db = Database::getInstance();
        $rest = $db->fetch(
            'SELECT r.*, o.name AS org_name
               FROM restaurants r
               JOIN organizations o ON o.id = r.organization_id
              WHERE r.id = ?',
            [$restaurantId]
        );
        if (!$rest) throw new \RuntimeException('Restaurant not found');

        $monthIso = $opts['month'] ?? null;
        $roiSvc   = new RoiService(
            new \App\PrivateData\RecommendationRepository(),
            new \App\PrivateData\PosSalesRepository(),
        );
        $summary  = $roiSvc->monthlySummary($restaurantId, $monthIso);
        $monthLabel = date('F Y', strtotime($summary['month_start']));

        // Pull recs decided OR measured in the same month — they're the
        // body of the report. Includes payload so we can show before/after.
        $monthStart = $summary['month_start'];
        $monthEnd   = date('Y-m-t 23:59:59', strtotime($monthStart));
        $recs = $db->fetchAll(
            'SELECT r.id, r.menu_item_id, r.kind, r.payload, r.narrative,
                    r.dollar_estimate_cents, r.status,
                    r.measured_impact_cents, r.decided_at, r.measured_at,
                    mi.name AS menu_item_name, mi.price_cents AS menu_item_price_cents
               FROM recommendations r
               LEFT JOIN menu_items mi ON mi.id = r.menu_item_id
              WHERE r.restaurant_id = ?
                AND r.status IN ("accepted", "measured")
                AND ((r.decided_at  BETWEEN ? AND ?)
                  OR (r.measured_at BETWEEN ? AND ?))
              ORDER BY COALESCE(r.measured_impact_cents, r.dollar_estimate_cents) DESC
              LIMIT 12',
            [$restaurantId, $monthStart, $monthEnd, $monthStart, $monthEnd]
        );
        foreach ($recs as &$r) {
            $r['payload'] = $r['payload'] ? json_decode($r['payload'], true) : [];
        }

        // Top overpay flags — highest plate-cost items from the same window.
        // Phase-1 honest framing: this surfaces the highest-impact items
        // (which is what the cost page also leads with).
        $overpay = $db->fetchAll(
            'SELECT mi.name, pc.true_cost_cents, mi.price_cents
               FROM menu_items mi
               LEFT JOIN plate_costs pc ON pc.menu_item_id = mi.id
              WHERE mi.restaurant_id = ?
                AND mi.is_active = 1
                AND pc.true_cost_cents IS NOT NULL
              ORDER BY pc.true_cost_cents DESC
              LIMIT 8',
            [$restaurantId]
        );

        // COGS attribution — most-recent USDA batch covering the restaurant's region.
        $cogs = $db->fetch(
            'SELECT source, region, MAX(as_of) AS as_of
               FROM cogs_benchmark
              WHERE source IN ("usda", "greendock")
                AND (region IS NULL OR region = ?)
              GROUP BY source, region
              ORDER BY as_of DESC
              LIMIT 1',
            [$rest['region'] ?? null]
        );

        // ── PDF setup ────────────────────────────────────────────────
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator('Carafe');
        $pdf->SetTitle(($rest['name'] ?? 'Restaurant') . ' — Money found ' . $monthLabel);
        $pdf->SetMargins(20, 22, 20);
        $pdf->setHeaderData('', 0, 'Carafe · ' . ($rest['org_name'] ?? ''), $rest['name'] ?? '', [26, 26, 46], [120, 72, 187]);
        $pdf->setHeaderFont(['helvetica', '', 9]);
        $pdf->setFooterFont(['helvetica', '', 8]);
        $pdf->SetAutoPageBreak(true, 25);

        // Use Nunito if its TTF was deployed alongside the app; otherwise
        // gracefully fall back to Helvetica. addTTFfont returns the font
        // name on success or false on failure — try/catch belt-and-braces.
        $bodyFont = 'helvetica';
        $nunitoPath = dirname(__DIR__, 2) . '/storage/fonts/Nunito.ttf';
        if (is_file($nunitoPath)) {
            try {
                $nm = \TCPDF_FONTS::addTTFfont($nunitoPath, 'TrueTypeUnicode', '', 32);
                if (is_string($nm) && $nm !== '') $bodyFont = $nm;
            } catch (\Throwable $e) {
                error_log('Nunito font load failed, falling back to Helvetica: ' . $e->getMessage());
            }
        }

        // ── Cover ────────────────────────────────────────────────────
        $pdf->AddPage();
        $pdf->SetTextColor(120, 72, 187); // --brand
        $pdf->SetFont($bodyFont, 'B', 11);
        $pdf->Ln(8);
        $pdf->Cell(0, 6, 'MONEY FOUND · ' . strtoupper($monthLabel), 0, 1);

        $pdf->Ln(2);
        $pdf->SetTextColor(15, 138, 74); // --money-positive
        $pdf->SetFont($bodyFont, 'B', 44);
        $pdf->Cell(0, 18, '$' . number_format(intdiv($summary['found_cents'], 100)), 0, 1);

        $pdf->SetTextColor(74, 74, 90); // --body
        $pdf->SetFont($bodyFont, '', 11);
        $measured = '$' . number_format(intdiv($summary['measured_cents'], 100));
        $pending  = '$' . number_format(intdiv($summary['pending_cents'], 100));
        $pdf->MultiCell(0, 6,
            $measured . ' already measured against your sales · ' . $pending . ' pending from '
            . $summary['accepted_count'] . ' accepted move' . ($summary['accepted_count'] === 1 ? '' : 's') . '.'
        );

        $pdf->Ln(6);
        $pdf->SetDrawColor(232, 232, 238); // --line-soft
        $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
        $pdf->Ln(4);

        $pdf->SetTextColor(26, 26, 46);
        $pdf->SetFont($bodyFont, 'B', 14);
        $pdf->Cell(0, 8, $rest['name'] ?? 'Restaurant', 0, 1);
        $pdf->SetFont($bodyFont, '', 10);
        $pdf->SetTextColor(107, 107, 123);
        if (!empty($rest['address'])) $pdf->Cell(0, 5, (string) $rest['address'], 0, 1);
        $pdf->Cell(0, 5, 'Report generated ' . date('M j, Y'), 0, 1);

        // ── Section: Accepted / measured recommendations ─────────────
        $pdf->Ln(8);
        $pdf->SetTextColor(26, 26, 46);
        $pdf->SetFont($bodyFont, 'B', 13);
        $pdf->Cell(0, 8, 'Moves you accepted this month', 0, 1);
        $pdf->SetTextColor(107, 107, 123);
        $pdf->SetFont($bodyFont, '', 10);
        $pdf->MultiCell(0, 5,
            'Each row is a recommendation Carafe surfaced and you accepted. '
          . 'Measured impact = post-decision sales × the price delta. We hold a 14-day floor before claiming a number, '
          . 'so newer accepts show as pending until they accumulate enough sales.'
        );
        $pdf->Ln(2);

        if (empty($recs)) {
            $pdf->SetFont($bodyFont, 'I', 10);
            $pdf->SetTextColor(107, 107, 123);
            $pdf->MultiCell(0, 6, 'No accepted moves in ' . $monthLabel . '. Future months will fill in here.');
        } else {
            self::renderRecsTable($pdf, $recs, $bodyFont);
        }

        // ── Section: Overpay flags caught ───────────────────────────
        if (!empty($overpay)) {
            $pdf->Ln(8);
            $pdf->SetTextColor(26, 26, 46);
            $pdf->SetFont($bodyFont, 'B', 13);
            $pdf->Cell(0, 8, 'Highest-cost items on the menu', 0, 1);
            $pdf->SetTextColor(107, 107, 123);
            $pdf->SetFont($bodyFont, '', 10);
            $pdf->MultiCell(0, 5,
                'Items with the largest plate cost — the leverage points if you renegotiate or substitute. '
              . 'Carafe surfaces overpay flags against the 35%-food-cost target on the Costs page.'
            );
            $pdf->Ln(2);
            self::renderOverpayTable($pdf, $overpay, $bodyFont);
        }

        // ── Methodology footnote ────────────────────────────────────
        $pdf->Ln(8);
        $pdf->SetDrawColor(232, 232, 238);
        $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
        $pdf->Ln(3);
        $pdf->SetFont($bodyFont, 'I', 8);
        $pdf->SetTextColor(107, 107, 123);
        $pdf->MultiCell(0, 4,
            'Methodology — Found = measured + pending. Measured: (post-decision $/unit − baseline $/unit) × post-decision units, '
          . 'using a 30-day measurement window with a 14-day minimum before any number is claimed. '
          . 'Pending: server-side dollar estimate at the time of acceptance, replaced by a measured number once the window closes. '
          . 'COGS source: ' . self::formatCogsCite($cogs) . '. '
          . 'Measurement window: ' . substr($monthStart, 0, 10) . ' → ' . substr($monthEnd, 0, 10) . '.'
        );

        // ── Save + reports row ───────────────────────────────────────
        $dir = dirname(__DIR__, 2) . '/storage/reports';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $filename = Database::uuid() . '.pdf';
        $path = $dir . '/' . $filename;
        $pdf->Output($path, 'F');

        try {
            $db->query(
                'INSERT INTO reports (id, area_id, filename, format, kind, created_at)
                 VALUES (?, NULL, ?, "pdf", "carafe_money_found", ?)',
                [Database::uuid(), $filename, date('Y-m-d H:i:s')]
            );
        } catch (\Throwable $e) {
            error_log('PdfReportService row insert failed (money_found): ' . $e->getMessage());
        }
        return $path;
    }

    private static function renderRecsTable(\TCPDF $pdf, array $recs, string $bodyFont): void
    {
        // Header
        $pdf->SetFillColor(243, 243, 247);   // --bg-panel
        $pdf->SetTextColor(107, 107, 123);
        $pdf->SetFont($bodyFont, 'B', 9);
        $pdf->Cell(60, 7, 'ITEM',     0, 0, 'L', true);
        $pdf->Cell(28, 7, 'BEFORE',   0, 0, 'R', true);
        $pdf->Cell(28, 7, 'AFTER',    0, 0, 'R', true);
        $pdf->Cell(28, 7, 'STATUS',   0, 0, 'L', true);
        $pdf->Cell(26, 7, 'IMPACT',   0, 1, 'R', true);

        // Rows — keep them height-bounded so the page-break math works.
        $pdf->SetFont($bodyFont, '', 10);
        foreach ($recs as $r) {
            // Page-break guard: skip to a new page if a row wouldn't fit
            // before the auto-break margin. TCPDF normally handles this,
            // but on a 12-row table we want the header to repeat too.
            if ($pdf->GetY() + 8 > $pdf->getPageHeight() - 30) {
                $pdf->AddPage();
                $pdf->SetFillColor(243, 243, 247);
                $pdf->SetTextColor(107, 107, 123);
                $pdf->SetFont($bodyFont, 'B', 9);
                $pdf->Cell(60, 7, 'ITEM',   0, 0, 'L', true);
                $pdf->Cell(28, 7, 'BEFORE', 0, 0, 'R', true);
                $pdf->Cell(28, 7, 'AFTER',  0, 0, 'R', true);
                $pdf->Cell(28, 7, 'STATUS', 0, 0, 'L', true);
                $pdf->Cell(26, 7, 'IMPACT', 0, 1, 'R', true);
                $pdf->SetFont($bodyFont, '', 10);
            }

            $payload = (array) ($r['payload'] ?? []);
            $before  = $payload['current_price_cents']
                     ?? $payload['baseline_price_cents']
                     ?? $r['menu_item_price_cents']
                     ?? null;
            $after   = $payload['recommended_price_cents']
                     ?? $payload['new_price_cents']
                     ?? ($before !== null && isset($payload['price_delta_cents'])
                           ? (int) $before + (int) $payload['price_delta_cents']
                           : null);
            $impact  = $r['status'] === 'measured'
                     ? (int) ($r['measured_impact_cents'] ?? 0)
                     : (int) ($r['dollar_estimate_cents'] ?? 0);
            $isMeasured = $r['status'] === 'measured';

            // Item name — truncated to fit
            $pdf->SetTextColor(26, 26, 46);
            $name = (string) ($r['menu_item_name'] ?? ucfirst(str_replace('_', ' ', $r['kind'])));
            if (strlen($name) > 32) $name = substr($name, 0, 30) . '…';
            $pdf->Cell(60, 7, $name, 0, 0);

            $pdf->SetTextColor(107, 107, 123);
            $pdf->Cell(28, 7, $before !== null ? '$' . number_format($before / 100, 2) : '—', 0, 0, 'R');
            $pdf->SetTextColor(26, 26, 46);
            $pdf->Cell(28, 7, $after  !== null ? '$' . number_format($after  / 100, 2) : '—', 0, 0, 'R');

            // Status pill (text-only — TCPDF doesn't do real pills, but
            // colored text + uppercase reads as one).
            if ($isMeasured) {
                $pdf->SetTextColor(15, 138, 74);
                $pdf->Cell(28, 7, 'MEASURED', 0, 0);
            } else {
                $pdf->SetTextColor(146, 103, 14); // warn
                $pdf->Cell(28, 7, 'PENDING', 0, 0);
            }

            $pdf->SetTextColor(15, 138, 74);
            $pdf->SetFont($bodyFont, 'B', 10);
            $pdf->Cell(26, 7, '$' . number_format(intdiv($impact, 100)) . '/mo', 0, 1, 'R');
            $pdf->SetFont($bodyFont, '', 10);

            // Per-row hairline divider so the eye tracks across columns
            // on dense tables.
            $pdf->SetDrawColor(232, 232, 238);
            $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
        }
    }

    private static function renderOverpayTable(\TCPDF $pdf, array $rows, string $bodyFont): void
    {
        $pdf->SetFillColor(243, 243, 247);
        $pdf->SetTextColor(107, 107, 123);
        $pdf->SetFont($bodyFont, 'B', 9);
        $pdf->Cell(90, 7, 'ITEM',       0, 0, 'L', true);
        $pdf->Cell(35, 7, 'PRICE',      0, 0, 'R', true);
        $pdf->Cell(45, 7, 'PLATE COST', 0, 1, 'R', true);

        $pdf->SetFont($bodyFont, '', 10);
        foreach ($rows as $row) {
            if ($pdf->GetY() + 8 > $pdf->getPageHeight() - 30) $pdf->AddPage();
            $name = (string) ($row['name'] ?? '—');
            if (strlen($name) > 46) $name = substr($name, 0, 44) . '…';
            $pdf->SetTextColor(26, 26, 46);
            $pdf->Cell(90, 7, $name, 0, 0);
            $pdf->SetTextColor(107, 107, 123);
            $pdf->Cell(35, 7, '$' . number_format(((int) $row['price_cents']) / 100, 2), 0, 0, 'R');
            $pdf->SetTextColor(26, 26, 46);
            $pdf->Cell(45, 7, '$' . number_format(((int) $row['true_cost_cents']) / 100, 2), 0, 1, 'R');
            $pdf->SetDrawColor(232, 232, 238);
            $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
        }
    }

    private static function formatCogsCite(?array $cogs): string
    {
        if (!$cogs || empty($cogs['as_of'])) return 'stub prices (USDA + GreenDock ingest pending)';
        $source = strtoupper((string) ($cogs['source'] ?? 'usda'));
        $region = $cogs['region'] ?: 'national';
        return $source . ' ' . $region . ', as of ' . date('M j, Y', strtotime((string) $cogs['as_of']));
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
