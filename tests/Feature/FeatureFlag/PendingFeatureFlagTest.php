<?php

namespace Tests\Feature\FeatureFlag;

use App\Support\FeatureCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;
use App\Actions\V1\Feature\FeatureIndexAction;

/**
 * A flag whose module does not exist says so on the page.
 *
 * ## Why
 *
 * `translations` and `activity_logs` were declared in config before their
 * modules shipped. The switch rendered, the POST succeeded, the store row
 * flipped, the audit row was written and the counter moved — and nothing else
 * in the application changed. To an operator that reads as "I switched this
 * module off" when the truthful answer is "this switch controls nothing".
 *
 * The fix was a `pending` key in config, surfaced through the catalogue and
 * printed in the row. A flag that controls nothing should say so rather than
 * borrow the confidence of one that does.
 *
 * Both gaps are now closed: `activity_logs` shipped in Phase 10, and
 * `translations` was removed entirely in Phase 12. The test below keeps the
 * guard alive by asserting no flag carries the caveat.
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
        $snapshot = app(FeatureIndexAction::class)->run();

        return View::make('pages.features.index', [
            'errors' => new ViewErrorBag(),
            ...$snapshot,
            'manageable' => true,
        ])->render();
    }

    public function test_no_flag_carries_the_intent_only_caveat(): void
    {
        $pending = collect(FeatureCatalog::all())
            ->filter(fn (array $f): bool => $f['pending'] !== null)
            ->pluck('slug');

        $this->assertEmpty(
            $pending,
            'a flag is marked pending — either ship its module or remove the flag'
        );

        $html = $this->render();

        $this->assertStringNotContainsString(
            'records intent only',
            $html,
            'a flag that controls nothing was rendered without the caveat'
        );
    }

    public function test_a_wired_flag_carries_no_caveat(): void
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
