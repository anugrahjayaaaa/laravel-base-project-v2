<?php

namespace Tests\Arch;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * No inline fully-qualified class names in code — `app/` and `tests/` alike.
 *
 * The convention has been written down since Phase 1
 * (`docs/base/architecture/naming-conventions.md`: "Short import only — no FQCN
 * in code bodies"). `app/` honoured it; the test suite did not, and had drifted
 * to 253 inline references across 78 files by the time anyone noticed. A rule
 * that lives only in a document is a preference, so this makes it a gate.
 *
 * ## What it costs, and why it is worth it
 *
 * `\App\Models\RoleLookup::find('admin')` is the same class either way. What
 * differs is everything around the code:
 *
 *   - **grep.** `RoleLookup::` finds every use in the codebase. `\App\Models\
 *     RoleLookup::` finds every use that somebody happened to write inline, and
 *     misses the rest. A rename is a grep; a grep that only sees half the
 *     codebase is a broken rename.
 *   - **The reader.** Every symbol a line depends on should be visible in one
 *     place, and the top of the file is that place.
 *   - **The diff.** Moving a class moves one line in the file header instead of
 *     silently changing the meaning of every body that named it.
 *
 * ## What is deliberately NOT flagged
 *
 *   - **Docblocks and comments.** `@see \App\Support\FeatureCatalog::isActive()`
 *     is documentation that often names a class the file does not otherwise
 *     import. Converting those would add imports used by nothing.
 *   - **Strings.** `'App\Models\User'` in a morph map or a route action is a
 *     runtime value, not a reference the resolver follows. Rewriting it to a
 *     short name would break it.
 *   - **The global namespace.** `\Closure`, `\Throwable`, `\RuntimeException`,
 *     `\stdClass` and friends: importing a class with no namespace is noise, and
 *     the project's own Pint config does not do it either.
 *
 * Both carve-outs are properties of PHP's grammar rather than judgements, which
 * is what makes this testable rather than arguable.
 */
class NoInlineFqnTest extends TestCase
{
    /**
     * Every PHP file in the two trees the rule covers.
     *
     * @return array<string, array{0: string}>
     */
    public static function phpFiles(): array
    {
        $files = [];

        // `dirname(__DIR__, 2)`, not `base_path()`: a data provider is evaluated
        // before the application boots, and `base_path()` needs it. This file
        // lives in `tests/Arch`, which no regrouping moves, and the path it walks
        // to is the project root — not a class name, which is what the rule under
        // test is about.
        $root = realpath(__DIR__.'/../..');

        foreach (['app', 'tests'] as $tree) {
            $directory = $root.'/'.$tree;

            if (! is_dir($directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $path = $file->getPathname();
                    $files[$tree.'/'.substr($path, strlen($directory) + 1)] = [$path];
                }
            }
        }

        return $files;
    }

    /**
     * One file per data set, so a violation names the file that carries it
     * rather than listing every offender in the suite at once.
     */
    #[DataProvider('phpFiles')]
    public function test_no_file_names_a_class_inline(string $path): void
    {
        $offenders = [];

        foreach (self::inlineClasses($path) as $fqcn) {
            $offenders[] = $fqcn;
        }

        $this->assertSame(
            [],
            $offenders,
            basename($path).' uses '.count($offenders).' inline fully-qualified name(s); import them instead'
            ."\n    ".implode("\n    ", array_slice($offenders, 0, 10))
        );
    }

    /**
     * The inline fully-qualified names in one file, with the ones that are
     * allowed filtered out.
     *
     * Token-based rather than a regex, because the difference the rule turns on
     * is whether the name appears as CODE or as prose. A comment reading
     * `// see \App\Models\User::factory()` and the same call both contain the
     * characters; only the token stream knows which is which.
     *
     * @return array<int, string>
     */
    private static function inlineClasses(string $path): array
    {
        $tokens = @token_get_all((string) file_get_contents($path));
        $found = [];

        foreach ($tokens as $token) {
            if (! is_array($token) || $token[0] !== T_NAME_FULLY_QUALIFIED) {
                continue;
            }

            $fqcn = ltrim($token[1], '\\');

            // The global namespace — `\Closure`. One segment, no vendor in it.
            if (! str_contains($fqcn, '\\')) {
                continue;
            }

            $found[] = '\\'.$fqcn;
        }

        return array_values(array_unique($found));
    }
}
