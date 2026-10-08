<?php

namespace Tests\Feature;

use App\Support\FeatureCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A flag whose module does not exist says so on the page.
 *
 * ## Why
 *
 * `translations` and `activity_logs` are declared in config before their modules
 * ship. The switch rendered, the POST succeeded, the store row flipped, the
 * audit row was written and the counter moved — and nothing else in the
 * application changed. To an operator that reads as "I switched this module off"
 * when the truthful answer is "this switch controls nothing".
 *
 * The fix is a `pending` key in config, surfaced through the catalogue and
 * printed in the row. A flag that controls nothing should say so rather than
 * borrow the confidence of one that does.
 */
class PendingFeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The page as the real action hands it over.
     *
     * Not `FeatureCatalog::grouped()` directly: that carries identity only, and
     * `enabled` / `pending`-aware rows are the action's contract. A fixture that
     * assembled its own view data would be testing a shape the page never gets.
     */
    private function render(): string
    {
        $snapshot = app(\App\Actions\V1\Feature\FeatureIndexAction::class)->run();

        return View::make('pages.features.index', [
            'errors' => new ViewErrorBag(),
            ...$snapshot,
            'manageable' => true,
        ])->render();
    }

        public function test_a_flag_with_no_module_says_so (): void
    {
        $pending = collect(FeatureCatalog::all())
            ->filter(fn (array $f): bool => $f['pending'] !== null)
            ->pluck('slug');

        $this->assertNotEmpty($pending, 'no flag is marked pending — is every module built now?');

        $html = $this->render();

        foreach ($pending as $slug) {
            $row = $this->rowFor($html, $slug);

            $this->assertStringContainsString(
                'this switch records intent only',
                $row,
                "[{$slug}] controls nothing but the row does not say so"
            );
        }
    }

        public function test_a_wired_flag_carries_no_caveat (): void
    {
        // `users` is gated on 36 routes. A caveat on it would be noise.
        $this->assertNull(
            FeatureCatalog::find('users')['pending'],
            'a wired flag is marked pending'
        );

        $row = $this->rowFor($this->render(), 'users');

        $this->assertStringNotContainsString(
            'records intent only',
            $row,
            'a fully wired flag is caveated as if it controlled nothing'
        );
    }

    /**
     * `pulse` is gated through `pulse.middleware`, not a route this project
     * declares, so it is the flag most likely to be wrongly marked pending. It
     * must not be.
     */
        public function test_pulse_is_wired_and_not_marked_pending(): void
    {
        $this->assertNull(FeatureCatalog::find('pulse')['pending']);

        $group = app('router')->getMiddlewareGroups()['pulse'] ?? [];

        $this->assertContains('feature:pulse', $group);
    }

    private function rowFor(string $html, string $slug): string
    {
        preg_match_all('/<tr>.*?<\/tr>/s', $html, $rows);

        foreach ($rows[0] as $row) {
            if (str_contains($row, '<code>'.$slug.'</code>')) {
                return $row;
            }
        }

        $this->fail("no row rendered for [{$slug}]");
    }
}
