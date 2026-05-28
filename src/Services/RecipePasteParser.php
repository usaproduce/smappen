<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Parses pasted-from-spreadsheet text (TSV with header row optional) into
 * a structured preview that the operator can review before committing.
 *
 * Expected columns: item_name<TAB>ingredient<TAB>qty<TAB>unit
 *
 *   - Header row detection: if row 1's qty cell does not parse as a number,
 *     it's treated as a header and skipped.
 *   - Rows are grouped by item_name. Each group becomes one recipe.
 *   - Empty/whitespace rows are silently dropped.
 *   - Each row gets a status: 'ok' / 'warning' / 'error' plus a message.
 *   - The whole preview is rejected only if there are zero usable rows;
 *     individual rows with warnings still commit (so a typo in unit
 *     doesn't kill the import — operator sees it and fixes after).
 *
 * Intentionally permissive on whitespace and case. Intentionally strict
 * on qty (must parse > 0) since a zero-qty ingredient contributes nothing
 * to plate cost — better to flag it than silently save garbage.
 */
class RecipePasteParser
{
    private const ALLOWED_UNITS = [
        'oz', 'lb', 'g', 'kg', 'each', 'ea', 'tbsp', 'tsp', 'cup', 'ml', 'l', 'fl_oz', 'floz',
    ];

    private const UNIT_NORMALIZE = [
        'ea'   => 'each',
        'floz' => 'fl_oz',
        'pcs'  => 'each',
        'piece'=> 'each',
        'pieces'=> 'each',
    ];

    /**
     * @return array{
     *   groups: array<int, array{
     *     item_name: string,
     *     normalized_name: string,
     *     rows: array<int, array{ingredient_key: string, qty: float, unit: string, status: string, message: ?string}>,
     *     row_count: int,
     *     ok_count: int,
     *     warning_count: int,
     *     error_count: int
     *   }>,
     *   summary: array{total_rows: int, ok: int, warnings: int, errors: int, recipes: int}
     * }
     */
    public function parse(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $records = [];
        foreach ($lines as $i => $line) {
            if (trim($line) === '') continue;
            // Accept TAB primarily; fall back to multi-space or comma if the
            // user paste-lost their tabs (Excel sometimes converts to spaces
            // when copying single columns; CSV catches Google Sheets exports).
            $cells = null;
            if (strpos($line, "\t") !== false) {
                $cells = explode("\t", $line);
            } elseif (strpos($line, ',') !== false && substr_count($line, ',') >= 3) {
                $cells = str_getcsv($line);
            } else {
                $cells = preg_split('/\s{2,}/', $line);
            }
            $cells = array_map(fn($c) => trim((string) $c), $cells ?: []);
            // Pad to 4 so missing trailing cells produce clear errors.
            while (count($cells) < 4) $cells[] = '';
            $records[] = ['line' => $i + 1, 'cells' => $cells];
        }

        // Header detection: first record's qty cell doesn't parse as a number.
        if (!empty($records)) {
            $firstQty = $records[0]['cells'][2] ?? '';
            if (!is_numeric(str_replace([',', ' '], '', $firstQty))) {
                array_shift($records);
            }
        }

        $groups = [];
        $totalRows = 0; $okTotal = 0; $warnTotal = 0; $errTotal = 0;

        foreach ($records as $rec) {
            $cells = $rec['cells'];
            $itemName = $cells[0] ?? '';
            $ingredient = $cells[1] ?? '';
            $qtyRaw = $cells[2] ?? '';
            $unitRaw = strtolower($cells[3] ?? '');

            if ($itemName === '' && $ingredient === '' && $qtyRaw === '' && $unitRaw === '') continue;

            $status = 'ok';
            $message = null;

            if ($itemName === '') {
                $status = 'error';
                $message = 'Missing item_name';
            }
            if ($ingredient === '') {
                $status = 'error';
                $message = ($message ? $message . '; ' : '') . 'Missing ingredient';
            }

            $qty = (float) str_replace([',', ' '], '', $qtyRaw);
            if (!is_numeric(str_replace([',', ' '], '', $qtyRaw)) || $qty <= 0) {
                $status = 'error';
                $message = ($message ? $message . '; ' : '') . "Invalid qty '{$qtyRaw}'";
            }

            $unit = self::UNIT_NORMALIZE[$unitRaw] ?? $unitRaw;
            if ($unit === '') {
                $status = 'error';
                $message = ($message ? $message . '; ' : '') . 'Missing unit';
            } elseif (!in_array($unit, self::ALLOWED_UNITS, true)) {
                // Save it anyway as a warning — operator may have a custom unit.
                if ($status === 'ok') $status = 'warning';
                $message = ($message ? $message . '; ' : '') . "Unusual unit '{$unitRaw}' (will save)";
            }

            // Normalize ingredient_key: lower_snake_case, alphanumeric+underscore.
            $ingredientKey = $this->normalizeIngredientKey($ingredient);

            $normName = $this->normalizeItemName($itemName);
            if (!isset($groups[$normName])) {
                $groups[$normName] = [
                    'item_name' => $itemName,
                    'normalized_name' => $normName,
                    'rows' => [],
                    'row_count' => 0,
                    'ok_count' => 0,
                    'warning_count' => 0,
                    'error_count' => 0,
                ];
            }

            $groups[$normName]['rows'][] = [
                'ingredient_key' => $ingredientKey,
                'qty'            => $qty,
                'unit'           => $unit,
                'status'         => $status,
                'message'        => $message,
                'raw_ingredient' => $ingredient,
                'line'           => $rec['line'],
            ];
            $groups[$normName]['row_count']++;
            $totalRows++;
            if ($status === 'ok')      { $groups[$normName]['ok_count']++;      $okTotal++; }
            if ($status === 'warning') { $groups[$normName]['warning_count']++; $warnTotal++; }
            if ($status === 'error')   { $groups[$normName]['error_count']++;   $errTotal++; }
        }

        return [
            'groups'  => array_values($groups),
            'summary' => [
                'total_rows' => $totalRows,
                'ok'         => $okTotal,
                'warnings'   => $warnTotal,
                'errors'     => $errTotal,
                'recipes'    => count($groups),
            ],
        ];
    }

    public function normalizeIngredientKey(string $raw): string
    {
        $s = strtolower(trim($raw));
        $s = preg_replace('/[^a-z0-9]+/', '_', $s) ?? $s;
        $s = trim($s, '_');
        return $s;
    }

    public function normalizeItemName(string $raw): string
    {
        $s = strtolower(trim($raw));
        return preg_replace('/\s+/', ' ', $s) ?? $s;
    }
}
