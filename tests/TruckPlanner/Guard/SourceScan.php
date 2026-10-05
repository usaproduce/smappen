<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Guard;

/**
 * Reads PHP source the way the guard tests need it: as tokens, with comments dropped and paths written
 * with forward slashes. A guard looks at code, never at what a comment says about code.
 *
 * "The roots" are the Truck Planner backend (04_BACKEND.md 8.1): src/TruckPlanner, the Truck controllers,
 * scripts/truck and config/truck_planner.php.
 */
final class SourceScan
{
    /** @var array<string, list<array{0: int|string, 1: string, 2: int}>> */
    private static array $tokens = [];

    /** The repository root, forward slashes, no trailing slash. */
    public static function root(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 3));
    }

    /**
     * Every PHP file of the Truck Planner backend, relative to the repository root, sorted.
     *
     * @return list<string>
     */
    public static function roots(): array
    {
        $files = array_merge(
            self::phpFilesUnder('src/TruckPlanner'),
            self::phpFilesUnder('scripts/truck'),
            self::controllers(),
            ['config/truck_planner.php']
        );
        sort($files, SORT_STRING);
        return $files;
    }

    /**
     * src/Controllers/Truck*Controller.php, relative, sorted.
     *
     * @return list<string>
     */
    public static function controllers(): array
    {
        $files = [];
        foreach (glob(self::root() . '/src/Controllers/Truck*Controller.php') ?: [] as $path) {
            $files[] = 'src/Controllers/' . basename($path);
        }
        sort($files, SORT_STRING);
        return $files;
    }

    /**
     * Every *.php below a directory, relative to the repository root, sorted. Empty when it does not exist.
     *
     * @return list<string>
     */
    public static function phpFilesUnder(string $relativeDir): array
    {
        $dir = self::root() . '/' . $relativeDir;
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = substr(str_replace('\\', '/', $file->getPathname()), strlen(self::root()) + 1);
            }
        }
        sort($files, SORT_STRING);
        return $files;
    }

    /**
     * The tokens of a file without comments. Every token is [id, text, line]; a one-character token has
     * the character as its id.
     *
     * @return list<array{0: int|string, 1: string, 2: int}>
     */
    public static function tokens(string $relativeFile): array
    {
        if (!isset(self::$tokens[$relativeFile])) {
            $source = (string) file_get_contents(self::root() . '/' . $relativeFile);
            $out = [];
            $line = 1;
            foreach (token_get_all($source) as $token) {
                if (is_array($token)) {
                    $line = $token[2];
                    if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                        $line += substr_count($token[1], "\n");
                        continue;
                    }
                    $out[] = [$token[0], $token[1], $token[2]];
                    $line += substr_count($token[1], "\n");
                } else {
                    $out[] = [$token, $token, $line];
                }
            }
            self::$tokens[$relativeFile] = $out;
        }
        return self::$tokens[$relativeFile];
    }

    /** The source of a file with its comments removed. String literals stay. */
    public static function code(string $relativeFile): string
    {
        $code = '';
        foreach (self::tokens($relativeFile) as $token) {
            $code .= $token[1];
        }
        return $code;
    }

    /**
     * Plain function calls: a name followed by "(" that is not a method call, a static call, a declaration
     * or an instantiation. Names are lower-cased and stripped of any namespace ("\Round(" is "round").
     *
     * @return list<array{0: string, 1: int}> [function name, line]
     */
    public static function calls(string $relativeFile): array
    {
        $tokens = self::significant($relativeFile);
        $calls = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!self::isName($tokens[$i][0]) || ($tokens[$i + 1][0] ?? null) !== '(') {
                continue;
            }
            $before = $i > 0 ? $tokens[$i - 1][0] : null;
            if (in_array($before, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
                continue;
            }
            $calls[] = [self::baseName($tokens[$i][1]), $tokens[$i][2]];
        }
        return $calls;
    }

    /**
     * Method calls through an object: "->name(" and "?->name(". Names as written.
     *
     * @return list<array{0: string, 1: int}> [method name, line]
     */
    public static function methodCalls(string $relativeFile): array
    {
        $tokens = self::significant($relativeFile);
        $calls = [];
        $count = count($tokens);
        for ($i = 1; $i < $count; $i++) {
            if ($tokens[$i][0] === T_STRING && ($tokens[$i + 1][0] ?? null) === '('
                && in_array($tokens[$i - 1][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                $calls[] = [$tokens[$i][1], $tokens[$i][2]];
            }
        }
        return $calls;
    }

    /**
     * Class names that are instantiated ("new X") or used statically ("X::"), without namespace.
     *
     * @return list<array{0: string, 1: string, 2: int}> [class base name, "new" or "static", line]
     */
    public static function classUses(string $relativeFile): array
    {
        $tokens = self::significant($relativeFile);
        $uses = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!self::isName($tokens[$i][0])) {
                continue;
            }
            if ($i > 0 && $tokens[$i - 1][0] === T_NEW) {
                $uses[] = [self::lastSegment($tokens[$i][1]), 'new', $tokens[$i][2]];
            } elseif (($tokens[$i + 1][0] ?? null) === T_DOUBLE_COLON) {
                $uses[] = [self::lastSegment($tokens[$i][1]), 'static', $tokens[$i][2]];
            }
        }
        return $uses;
    }

    /**
     * Every identifier of a file: names of classes, functions, constants and members as written, with
     * namespaced names split into their parts. Variables carry their "$".
     *
     * @return list<array{0: string, 1: int}> [identifier, line]
     */
    public static function identifiers(string $relativeFile): array
    {
        $out = [];
        foreach (self::tokens($relativeFile) as $token) {
            if ($token[0] === T_VARIABLE) {
                $out[] = [$token[1], $token[2]];
            } elseif (self::isName($token[0])) {
                foreach (explode('\\', $token[1]) as $part) {
                    if ($part !== '' && $part !== 'namespace') {
                        $out[] = [$part, $token[2]];
                    }
                }
            }
        }
        return $out;
    }

    /**
     * The text of every string literal: quoted strings without their quotes, the literal parts of
     * interpolated strings and heredocs, and anything outside the PHP tags.
     *
     * @return list<array{0: string, 1: int}> [text, line]
     */
    public static function strings(string $relativeFile): array
    {
        $out = [];
        foreach (self::tokens($relativeFile) as $token) {
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $out[] = [substr($token[1], 1, -1), $token[2]];
            } elseif ($token[0] === T_ENCAPSED_AND_WHITESPACE || $token[0] === T_INLINE_HTML) {
                $out[] = [$token[1], $token[2]];
            }
        }
        return $out;
    }

    /** Does the file use the token with this id anywhere outside comments (for example T_POW)? */
    public static function hasToken(string $relativeFile, int $id): bool
    {
        foreach (self::tokens($relativeFile) as $token) {
            if ($token[0] === $id) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<array{0: int|string, 1: string, 2: int}> tokens without whitespace
     */
    private static function significant(string $relativeFile): array
    {
        $out = [];
        foreach (self::tokens($relativeFile) as $token) {
            if ($token[0] !== T_WHITESPACE) {
                $out[] = $token;
            }
        }
        return $out;
    }

    private static function isName(int|string $id): bool
    {
        return $id === T_STRING || $id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED || $id === T_NAME_RELATIVE;
    }

    private static function lastSegment(string $name): string
    {
        $cut = strrpos($name, '\\');
        return $cut === false ? $name : substr($name, $cut + 1);
    }

    private static function baseName(string $name): string
    {
        return strtolower(self::lastSegment($name));
    }
}
