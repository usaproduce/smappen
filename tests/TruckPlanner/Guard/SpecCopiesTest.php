<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Guard;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Three files are copies of a block of the specification: the two Truck Planner migrations and the CI
 * workflow. The block is the contract and the file is what runs, so the two change together or not at all.
 *
 * The texts are compared line by line. Line endings are not: git checks the same file out with either kind
 * (core.autocrlf).
 */
final class SpecCopiesTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     *         the file, the document, the heading its block stands under, the language of the block
     */
    public static function copies(): array
    {
        return [
            'migration 042' => ['src/Migrations/042_tp_reference_data.sql', 'docs/truck-planner/03_DATA.md', '### 9.1 ', 'sql'],
            'migration 043' => ['src/Migrations/043_truck_planner_core.sql', 'docs/truck-planner/04_BACKEND.md', '### 1.2 ', 'sql'],
            'workflow' => ['.github/workflows/truck-planner.yml', 'docs/truck-planner/04_BACKEND.md', '### 8.3 ', 'yaml'],
        ];
    }

    #[DataProvider('copies')]
    public function testTheFileIsTheBlockOfItsDocument(string $file, string $document, string $heading, string $language): void
    {
        $block = self::firstBlock(self::read($document), $heading, $language);
        self::assertNotNull($block, $document . ' has no ' . $language . ' block under "' . trim($heading) . '"');
        self::assertSame(
            explode("\n", $block),
            explode("\n", self::read($file)),
            $file . ' and its block in ' . $document . ' differ: change both in the same commit'
        );
    }

    public function testABlockIsFoundUnderItsHeadingOnly(): void
    {
        $text = "# Title\n\n```sql\nSELECT 0;\n```\n\n### 1.2 `a.sql`\n\nWords.\n\n```yaml\nb: 1\n```\n\n```sql\n-- one\nSELECT 1;\n\nSELECT 2;\n```\n\n"
            . "### 1.3 Next\n\n```sql\nSELECT 3;\n```\n";
        self::assertSame("-- one\nSELECT 1;\n\nSELECT 2;\n", self::firstBlock($text, '### 1.2 ', 'sql'));
        self::assertSame("b: 1\n", self::firstBlock($text, '### 1.2 ', 'yaml'));
        self::assertSame("SELECT 3;\n", self::firstBlock($text, '### 1.3 ', 'sql'));
        self::assertNull(self::firstBlock($text, '### 1.3 ', 'yaml'), 'no block of that language under the heading');
        self::assertNull(self::firstBlock($text, '### 9.9 ', 'sql'), 'no such heading');
        self::assertNull(self::firstBlock("### 1.2 x\n\n```sql\nSELECT 1;\n", '### 1.2 ', 'sql'), 'a block that is not closed');
    }

    /**
     * The first fenced block of a language between a heading and the next heading of the same or a higher
     * level, with a line feed after every line. Null when there is none.
     */
    private static function firstBlock(string $text, string $heading, string $language): ?string
    {
        $level = strspn($heading, '#');
        $open = false;
        $inside = false;
        $lines = [];
        foreach (explode("\n", $text) as $line) {
            if (!$open) {
                $open = str_starts_with($line, $heading);
                continue;
            }
            if ($inside) {
                if ($line === '```') {
                    return implode("\n", $lines) . "\n";
                }
                $lines[] = $line;
                continue;
            }
            $marks = strspn($line, '#');
            if ($marks > 0 && $marks <= $level && ($line[$marks] ?? '') === ' ') {
                return null;
            }
            $inside = $line === '```' . $language;
        }
        return null;
    }

    /** A file of the repository with line feeds only and exactly one at its end. */
    private static function read(string $relative): string
    {
        $path = SourceScan::root() . '/' . $relative;
        self::assertFileExists($path);
        $text = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($path));
        return rtrim($text, "\n") . "\n";
    }
}
