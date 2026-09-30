<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * No layout may reach a third-party host for a stylesheet.
 *
 * Both icon stylesheets were loaded from a CDN. They were render-blocking, so
 * the cost was not the download but everything the page could not do until they
 * arrived: 730 ms of blocking CSS on a warm connection, with ten remote font
 * files behind it (4.4 s fetched serially). That is the whole page held back so
 * that icons could draw — and a slow or unreachable CDN held it back entirely,
 * since icons are not something that can render "a bit late".
 *
 * Bootstrap and AdminLTE were already vendored locally, so these two were an
 * inconsistency rather than a decision: 126 fa-* and 66 bi- classes are used
 * across the views, so they are not leftovers either.
 *
 * This asserts the invariant rather than the two current URLs, so swapping an
 * icon library again cannot quietly reintroduce the dependency.
 */
class NoCdnStylesheetsTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function layoutProvider(): array
    {
        return [
            'app' => [__DIR__.'/../../resources/views/layouts/app.blade.php'],
            'auth' => [__DIR__.'/../../resources/views/layouts/auth.blade.php'],
            'guest' => [__DIR__.'/../../resources/views/layouts/guest.blade.php'],
        ];
    }

    #[DataProvider('layoutProvider')]
    public function test_no_layout_loads_a_stylesheet_from_a_third_party(string $path): void
    {
        $html = file_get_contents($path);

        // Only stylesheets: a third-party FONT is a different problem and is
        // allowed until it is measured rather than assumed.
        preg_match_all('/<link[^>]+rel=["\']stylesheet["\'][^>]*>/i', $html, $matches);

        foreach ($matches[0] as $tag) {
            $this->assertDoesNotMatchRegularExpression(
                '#https?://#i',
                $tag,
                basename($path)." loads a stylesheet from a third party:\n".$tag
            );
        }
    }

    /**
     * The reason this class exists after the first attempt at it.
     *
     * Stripping the legacy `url(...ttf)` references out of a minified stylesheet
     * leaves their `format("truetype")` descriptors behind as orphans:
     *
     *     src:url(fa-solid-900.woff2) format("woff2") format("truetype")
     *
     * A `format()` with no `url()` beside it is an unknown descriptor, so the
     * whole `@font-face` block is invalid and the browser discards it — the
     * stylesheet loads, every rule applies, and no icon renders. Every check
     * that only looks for remote URLs passed on that broken file: the fonts
     * existed, they were all local, and nothing 404'd.
     *
     * So assert the shapes that actually decide whether the icons draw.
     */
    public function test_a_vendored_stylesheet_has_no_orphaned_font_descriptors(): void
    {
        $root = public_path('vendor');

        $css = [
            'fontawesome/fontawesome.min.css',
            'bootstrap-icons/bootstrap-icons.min.css',
        ];

        foreach ($css as $file) {
            $contents = file_get_contents($root.'/'.$file);

            preg_match_all('/src:([^;}]+)/i', $contents, $blocks);

            $this->assertNotEmpty($blocks[1], "{$file} has no @font-face src to check");

            foreach ($blocks[1] as $src) {
                $urls = preg_match_all('/url\(/i', $src);
                $formats = preg_match_all('/format\(/i', $src);

                $this->assertSame(
                    $urls,
                    $formats,
                    "{$file} has an orphaned font descriptor — a format() with no url() beside it:\n{$src}"
                );

                $this->assertGreaterThan(
                    0,
                    $urls,
                    "{$file} has a format() but no url() at all:\n{$src}"
                );
            }
        }
    }

    /**
     * A vendored stylesheet that 404s renders unstyled, silently — no test
     * fails, no exception is raised. Asserting the files exist is the only
     * thing standing between a bad vendor fetch and a broken admin panel.
     */
    public function test_every_vendored_icon_asset_exists(): void
    {
        $root = public_path('vendor');

        $css = [
            'fontawesome/fontawesome.min.css',
            'bootstrap-icons/bootstrap-icons.min.css',
        ];

        foreach ($css as $file) {
            $this->assertFileExists($root.'/'.$file);
        }

        // Every url() in those files must resolve to a file next to it, or the
        // browser falls back to a request that no longer exists.
        foreach ($css as $file) {
            $contents = file_get_contents($root.'/'.$file);

            preg_match_all('/url\(["\']?([^"\')]+)["\']?\)/i', $contents, $matches);

            foreach (array_unique($matches[1]) as $reference) {
                if (str_starts_with($reference, 'data:')) {
                    continue;
                }

                $this->assertFileExists(
                    dirname($root.'/'.$file).'/'.$reference,
                    "{$file} references {$reference}, which is not vendored"
                );
            }
        }
    }
}
