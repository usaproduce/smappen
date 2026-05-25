<?php
declare(strict_types=1);

namespace App\Tests\DataWall;

use PHPUnit\Framework\TestCase;

/**
 * Carafe data wall — automated enforcement.
 *
 * See spec §1.5 ("two sacred data reservoirs, walled apart") and §7
 * (data model). The private reservoir powers the money engine for the
 * restaurant; the market/funnel reservoir powers the supplier funnel
 * to GreenDock. They must never share a query path.
 *
 * This file is grep-the-source, not static analysis. PHPStan/Psalm
 * aren't set up in this repo; introducing them is out of scope. Grep
 * tests are crude but bulletproof for the specific invariant we care
 * about (a string match on a table name is sufficient evidence of a
 * violation; we don't need to parse the AST).
 *
 * If you're adding a new table or namespace and this test fails, the
 * fix is almost certainly to move the new code into the correct
 * namespace, not to weaken the assertion.
 */
class DataWallTest extends TestCase
{
    /**
     * Private-reservoir table names. Spec §7. Updated as new tables are
     * added.
     *
     * `restaurants` is deliberately NOT in this list — it's the org-scoped
     * entity, not transactional private data, and market-side code may
     * legitimately need to look up region/location from it. The wall is
     * around SALES, MENU, COSTS, LABOR, RECOMMENDATIONS, GOALS — never
     * around the entity itself.
     */
    private const PRIVATE_TABLES = [
        'pos_integrations',
        'pos_sales',
        'menu_items',
        'recipes',
        'recipe_ingredients',
        'plate_costs',
        'labor_shifts',
        'recommendations',
        'goals',
        'goal_snapshots',
        'plans_sandbox',
    ];

    /** Market/funnel-reservoir table names. */
    private const MARKET_TABLES = [
        'vendors',
        'vendor_listings',
        'vendor_claims',
        'vendor_promotions',
        'comparison_requests',
        'supplier_leads',
    ];

    private function srcPath(): string
    {
        return dirname(__DIR__, 2) . '/src';
    }

    /** Recursively list .php files under a directory. */
    private function phpFiles(string $dir): array
    {
        if (!is_dir($dir)) return [];
        $out = [];
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iter as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                $out[] = $f->getPathname();
            }
        }
        return $out;
    }

    /**
     * Funnel/market code may not reference any private-reservoir table
     * name or import any PrivateData repository.
     */
    public function testMarketDataDoesNotReferencePrivateTables(): void
    {
        $marketFiles = $this->phpFiles($this->srcPath() . '/MarketData');
        foreach ($marketFiles as $f) {
            $body = (string) file_get_contents($f);
            foreach (self::PRIVATE_TABLES as $t) {
                $this->assertStringNotContainsString(
                    $t,
                    $body,
                    "DATA WALL VIOLATION: $f references private table `$t`. "
                    . "Funnel/market code is forbidden from reading restaurant "
                    . "private data — see spec §1.5 + tests/DataWall/DataWallTest.php."
                );
            }
            $this->assertStringNotContainsString(
                'App\\PrivateData\\',
                $body,
                "DATA WALL VIOLATION: $f imports App\\PrivateData\\. "
                . "Funnel/market code must not depend on the private reservoir."
            );
        }
        $this->assertTrue(true); // PHPUnit requires at least one assertion when foreach is empty
    }

    /**
     * Private code may not import market repositories. Private is
     * ignorant of the funnel — it does its job without looking sideways.
     */
    public function testPrivateDataDoesNotImportMarketData(): void
    {
        $privateFiles = $this->phpFiles($this->srcPath() . '/PrivateData');
        foreach ($privateFiles as $f) {
            $body = (string) file_get_contents($f);
            $this->assertStringNotContainsString(
                'App\\MarketData\\',
                $body,
                "DATA WALL VIOLATION: $f imports App\\MarketData\\. "
                . "Private code must not know about the funnel."
            );
        }
        $this->assertTrue(true);
    }

    /**
     * `supplier_leads` rows may only be inserted by LeadFunnelService.
     * Search the whole src/ tree for `INTO supplier_leads` and assert
     * exactly one file matches (and that it's the right one).
     */
    public function testSupplierLeadsInsertedOnlyByLeadFunnelService(): void
    {
        $hits = [];
        foreach ($this->phpFiles($this->srcPath()) as $f) {
            $body = (string) file_get_contents($f);
            // Allow both bare "supplier_leads" inside an INSERT statement and
            // table-backtick variants. Skip the test file's own self-mentions.
            if (preg_match('/INTO\s+`?supplier_leads`?\b/i', $body)) {
                $hits[] = $f;
            }
        }

        // Allowed in Phase 1: zero or one match. Once LeadFunnelService is
        // implemented (Phase 2), the test enforces it's the only writer.
        if (count($hits) === 0) {
            $this->assertTrue(true, 'No supplier_leads writers yet — Phase 2 not started.');
            return;
        }

        $this->assertCount(
            1,
            $hits,
            "DATA WALL VIOLATION: multiple files INSERT INTO supplier_leads:\n  " . implode("\n  ", $hits)
            . "\nOnly App\\MarketData\\LeadFunnelService is allowed to write to the funnel outbox."
        );
        $this->assertStringEndsWith(
            'LeadFunnelService.php',
            $hits[0],
            "DATA WALL VIOLATION: supplier_leads inserted by {$hits[0]}, not LeadFunnelService."
        );
    }

    /**
     * Sanity: the private-reservoir tables that have been built so far must
     * actually live behind PrivateData. If a controller is found inserting
     * into one of these tables directly, that's a leak.
     */
    public function testPrivateTableWritesGoThroughPrivateDataNamespace(): void
    {
        $allowedDirs = [$this->srcPath() . '/PrivateData', $this->srcPath() . '/Migrations'];
        foreach (self::PRIVATE_TABLES as $t) {
            $hits = [];
            foreach ($this->phpFiles($this->srcPath()) as $f) {
                $body = (string) file_get_contents($f);
                if (preg_match('/INTO\s+`?' . preg_quote($t, '/') . '`?\b/i', $body)) {
                    $allowed = false;
                    foreach ($allowedDirs as $d) {
                        if (str_starts_with($f, $d)) { $allowed = true; break; }
                    }
                    if (!$allowed) $hits[] = $f;
                }
            }
            $this->assertEmpty(
                $hits,
                "DATA WALL VIOLATION: `$t` is a private table but INSERTs were found outside "
                . "App\\PrivateData\\ in:\n  " . implode("\n  ", $hits)
            );
        }
    }
}
