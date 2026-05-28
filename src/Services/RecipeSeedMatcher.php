<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Matches an arbitrary menu item name (e.g. "Spaghetti Carbonara", "Mom's
 * Famous Burger") against the curated seed dictionary at
 * config/recipe_seeds.php and returns a draft recipe — so the operator
 * confirms or edits, but never faces a blank page.
 *
 * Matching strategy (simple on purpose; the dictionary is small):
 *   1. Normalize: lowercase, strip non-alphanumeric, drop common modifier
 *      words ("classic", "house", "homemade", "famous", "signature",
 *      etc.). Keeps "chicken caesar" matchable against "chickencaesarsalad".
 *   2. Exact concatenated match on key or alias wins.
 *   3. Otherwise, find seeds where every significant word of the query
 *      appears as a substring of the seed key or alias — pick shortest
 *      (most specific). Tiebreak by category hint.
 *   4. Otherwise, single-word substring fallback ("BBQ Bacon Burger" →
 *      cheeseburger via "burger").
 *   5. No match → return an empty draft (matched=false) so the operator
 *      still gets a form, not a blank page.
 *
 * No Levenshtein on purpose — typos are rare for names operators just
 * typed, and fuzzy matching produces confusing suggestions ("Salmon
 * Bowl" → caesar salad). Be honest: when there's no good match, say so.
 */
class RecipeSeedMatcher
{
    private const MODIFIER_WORDS = [
        'classic', 'house', 'housemade', 'homemade', 'famous', 'signature',
        'the', 'with', 'and', 'of', 'a', 'an', 'fresh', 'grilled', 'pan',
        'seared', 'roasted', 'baked', 'fried', 'crispy', 'spicy', 'mild',
        'small', 'large', 'medium', 'side', 'plate', 'bowl', 'sandwich',
        'special', 'our', 'my', 'moms', 'dads', 'chefs', 'chef',
    ];

    /** @var array<int, array<string, mixed>> */
    private array $seeds;

    public function __construct(?array $seeds = null)
    {
        $this->seeds = $seeds ?? require __DIR__ . '/../../config/recipe_seeds.php';
    }

    /**
     * @return array{name: string, category: ?string, ingredients: array<int, array{ingredient_key: string, qty: float, unit: string}>, matched: bool, source_key: ?string}
     */
    public function suggest(string $menuItemName, ?string $category = null): array
    {
        $words = $this->significantWords($menuItemName);
        $concat = implode('', $words);

        // 1. Exact concatenated match on key or alias.
        foreach ($this->seeds as $seed) {
            $candidates = array_merge([$seed['key']], $seed['aliases'] ?? []);
            foreach ($candidates as $c) {
                if ($concat === $c) {
                    return $this->shape($menuItemName, $seed, true);
                }
            }
        }

        // 2. All-words-as-substring match across any single candidate string.
        $allWordsMatches = [];
        foreach ($this->seeds as $seed) {
            $candidates = array_merge([$seed['key']], $seed['aliases'] ?? []);
            foreach ($candidates as $c) {
                if (empty($words)) continue;
                $allIn = true;
                foreach ($words as $w) {
                    if (strpos($c, $w) === false) { $allIn = false; break; }
                }
                if ($allIn) {
                    $score = strlen($c);
                    if ($category !== null && ($seed['category'] ?? null) === $category) $score -= 5;
                    $allWordsMatches[] = ['seed' => $seed, 'score' => $score];
                    break;
                }
            }
        }
        if (!empty($allWordsMatches)) {
            usort($allWordsMatches, fn($a, $b) => $a['score'] <=> $b['score']);
            return $this->shape($menuItemName, $allWordsMatches[0]['seed'], true);
        }

        // 3. Single-word substring fallback ("BBQ Bacon Burger" → burger).
        foreach ($words as $w) {
            if (strlen($w) < 4) continue;
            $hits = [];
            foreach ($this->seeds as $seed) {
                $candidates = array_merge([$seed['key']], $seed['aliases'] ?? []);
                foreach ($candidates as $c) {
                    if (strpos($c, $w) !== false) {
                        $score = strlen($c);
                        if ($category !== null && ($seed['category'] ?? null) === $category) $score -= 5;
                        $hits[] = ['seed' => $seed, 'score' => $score];
                        break;
                    }
                }
            }
            if (!empty($hits)) {
                usort($hits, fn($a, $b) => $a['score'] <=> $b['score']);
                return $this->shape($menuItemName, $hits[0]['seed'], true);
            }
        }

        // 4. No match — honest empty draft.
        return [
            'name'        => $menuItemName,
            'category'    => $category,
            'ingredients' => [],
            'matched'     => false,
            'source_key'  => null,
        ];
    }

    /** Lowercased, alphanumeric, modifier-words removed; order preserved. */
    private function significantWords(string $s): array
    {
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s) ?? $s;
        $s = trim((string) preg_replace('/\s+/', ' ', $s));
        if ($s === '') return [];
        $out = [];
        foreach (explode(' ', $s) as $w) {
            if ($w === '' || in_array($w, self::MODIFIER_WORDS, true)) continue;
            $out[] = $w;
        }
        return $out;
    }

    /**
     * @param array{key: string, aliases?: array, category?: string|null, ingredients: array} $seed
     */
    private function shape(string $menuItemName, array $seed, bool $matched): array
    {
        return [
            'name'        => $menuItemName,
            'category'    => $seed['category'] ?? null,
            'ingredients' => array_map(
                fn($i) => [
                    'ingredient_key' => (string) $i['ingredient_key'],
                    'qty'            => (float)  $i['qty'],
                    'unit'           => (string) $i['unit'],
                ],
                $seed['ingredients'] ?? []
            ),
            'matched'     => $matched,
            'source_key'  => $seed['key'] ?? null,
        ];
    }
}
