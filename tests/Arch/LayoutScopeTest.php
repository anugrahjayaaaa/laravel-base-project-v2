<?php

declare(strict_types=1);

namespace Tests\Arch;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo as FinderFile;

/**
 * A view variable that reaches the layout can break the layout.
 *
 * ## Why this exists
 *
 * `layouts/partials/modals/confirmation` reads `$confirmLabel ?? $action`. A page that defines
 * `$action` — in `@php(...)`, in a `@foreach` binding, anywhere — has that object
 * replaced by whatever the page assigned, and every page rendering the
 * confirmation modal 500s on `htmlspecialchars(array)`.
 *
 * It cost two 500s in one session, both of them on pages that were otherwise
 * green, and neither was visible until the variable happened to be set. A rule
 * that is only known by the person who hit it is not a rule; this makes it a
 * suite failure instead.
 *
 * The names are the layout's, read from the partial itself rather than copied
 * into a list that would itself go stale. A name added to the modal later is
 * picked up here with no edit to this file.
 */
final class LayoutScopeTest extends TestCase
{
    /**
     * `__DIR__` and not `resource_path()`: this extends PHPUnit's TestCase, so
     * there is no container to ask — the same reason the other arch tests resolve
     * paths with `realpath(__DIR__.'/../../…')`.
     */
    private const VIEWS = __DIR__.'/../../resources/views';

    /**
     * Variable names the confirmation modal falls back to.
     *
     * @return array<int, string>
     */
    private static function reservedNames(): array
    {
        $partial = (string) file_get_contents(self::VIEWS.'/layouts/partials/modals/confirmation.blade.php');

        preg_match_all('/\$\{?([A-Za-z_][A-Za-z0-9_]*)\}?\s*(\?\?|\?:)/', $partial, $matches);

        return array_values(array_unique($matches[1]));
    }

    public function test_no_page_view_shadows_a_variable_the_layout_reads(): void
    {
        $reserved = self::reservedNames();

        $this->assertNotEmpty($reserved, 'precondition: the modal partial declares fallback variables');

        $offenders = [];

        // `Finder`, not `glob()`: `**` is not recursive in PHP's glob, so a glob
        // scan reads one directory level and passes vacuously on everything else.
        $finder = (new Finder())->files()->in(self::VIEWS)->name('*.blade.php');
        $seen = 0;

        /** @var FinderFile $file */
        foreach ($finder as $file) {
            $seen++;
            $source = $file->getContents();
            $relative = str_replace(self::VIEWS.'/', '', $file->getPathname());

            // The layout itself is where these names are DECLARED; scanning it
            // would report the owner of the variables as their first offender.
            if (str_starts_with($relative, 'layouts/')) {
                continue;
            }

            foreach ($reserved as $name) {
                // An ASSIGNMENT is what shadows it. A read (`{{ $action }}`) inside
                // a layout the page extends is the normal case and must not fail.
                if (preg_match('/@[Pp]hp\([^)]*\$'.$name.'\s*=[^=]|<\?php\s+\$'.$name.'\s*=[^=]|\bset\(\s*\$'.$name.'\s*=/', $source) === 1) {
                    $offenders[] = $relative.' assigns $'.$name;
                }
            }
        }

        // A scan that read nothing would report a clean bill of health for a
        // directory it never opened.
        self::assertGreaterThan(30, $seen, 'the view scanner read almost no files — this test is vacuous');

        self::assertSame(
            [],
            $offenders,
            "these views assign a variable the layout reads, which 500s the confirmation modal:\n".implode("\n", $offenders)
        );
    }

    public function test_the_reserved_names_are_read_from_the_partial(): void
    {
        // Guards the guard: if the partial were renamed or emptied, the test above
        // would pass because there was nothing left to shadow.
        self::assertFileExists(self::VIEWS.'/layouts/partials/modals/confirmation.blade.php');

        self::assertContains('confirmLabel', self::reservedNames());
    }
}
