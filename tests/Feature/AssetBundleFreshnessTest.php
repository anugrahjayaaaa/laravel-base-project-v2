<?php

namespace Tests\Feature;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * The bulk bar's dropdown is built by JavaScript from a Vite bundle.
 *
 * Nothing in the PHP suite can catch a stale bundle: Blade renders the same
 * `data-bulk-states` either way, and every request test passes while the
 * dropdown shows "-- Action --" forever. That is exactly the failure this file
 * exists to prevent — the actions were added to source, the page was correct,
 * and the browser was still running the pre-feature bundle.
 *
 * @see \Tests\Feature\FeatureFlagUiRenderTest for the markup these keys must match
 */
class AssetBundleFreshnessTest extends TestCase
{
    /** Actions the features bulk bar offers, per ACTION_CONFIG. */
    private const FEATURE_ACTIONS = ['enable_feature', 'disable_feature'];

    public function test_the_served_bundle_contains_the_feature_bulk_actions(): void
    {
        $bundle = $this->appBundle();

        foreach (self::FEATURE_ACTIONS as $action) {
            $this->assertStringContainsString(
                $action,
                $bundle,
                "[{$action}] is missing from the built bundle — the bulk dropdown will be empty. Run `npm run build`."
            );
        }
    }

    /**
     * The select-all handler is edited in the same file, so a rebuild that
     * picked up the actions but not this would still leave every checkbox dead.
     */
    public function test_the_served_bundle_contains_the_select_all_handler(): void
    {
        $this->assertStringContainsString(
            'bulk-check-all',
            $this->appBundle(),
            'bulk-check-all is missing from the built bundle — select-all is dead. Run `npm run build`.'
        );
    }

    /**
     * Every page that ships a select-all must be matched by the driver's lookup.
     *
     * This is the regression that broke select-all on users and roles: the
     * driver was changed to `querySelectorAll('.bulk-check-all')` for the
     * features page, which returns nothing on the pages still using
     * `id="bulkSelectAll"`. Nothing failed — the handler simply was never
     * bound, and the checkbox looked inert.
     *
     * Read from the VIEWS, not hard-coded, so a page changing its markup fails
     * here instead of in a browser.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function test_the_driver_matches_every_page_s_select_all_markup (): void
    {
        $bundle = $this->appBundle();

        $selectors = [];

        foreach (Finder::create()->in(resource_path('views/pages'))->name('index.blade.php') as $file) {
            $html = (string) file_get_contents($file->getPathname());

            if (str_contains($html, 'id="bulkSelectAll"')) {
                $selectors['#bulkSelectAll'][] = $file->getFilename();
            }

            if (str_contains($html, 'bulk-check-all')) {
                $selectors['.bulk-check-all'][] = $file->getFilename();
            }
        }

        $this->assertNotEmpty($selectors, 'no page ships a select-all at all');

        foreach (array_keys($selectors) as $selector) {
            $this->assertStringContainsString(
                $selector,
                $bundle,
                "pages using {$selector} are not matched by the driver: "
                    .implode(', ', $selectors[$selector])
            );
        }
    }

    /**
     * Read the app entrypoint as the page actually receives it.
     *
     * Goes through the manifest rather than globbing `assets/`, because Vite
     * content-hashes filenames and the old bundle stays on disk after a
     * rebuild. A glob would read whichever file sorted first — often the stale
     * one — and pass or fail for the wrong reason.
     */
    private function appBundle(): string
    {
        $manifestPath = public_path('build/manifest.json');

        $this->assertFileExists(
            $manifestPath,
            'no Vite manifest — run `npm run build`'
        );

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        $this->assertIsArray($manifest, 'the Vite manifest is not valid JSON');

        $entry = $manifest['resources/js/app.js']['file'] ?? null;

        $this->assertNotNull($entry, 'app.js is missing from the Vite manifest');

        return (string) file_get_contents(public_path('build/'.$entry));
    }
}
