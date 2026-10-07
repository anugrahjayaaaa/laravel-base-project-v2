<?php

namespace Tests\Feature;

use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P7-F2 — every action the bulk bar offers must resolve to real copy.
 *
 * `populateDropdown` skips any action it cannot label, and `resolveAction`
 * warns and returns null for an unknown key. Both fail SILENTLY: the dropdown
 * just comes up empty, with no console error that reaches a test run. That is
 * how the features bulk bar shipped with an empty dropdown in the first place.
 *
 * Three things have to agree, and this reads all three from the SOURCE (not the
 * bundle — the bundle is minified, so a key would not be greppable):
 *
 *   1. `data-bulk-states` / `data-bulk-keys` in the view — what the page offers
 *   2. `actionOptions` in bulk-actions.js          — the dropdown's labels
 *   3. `ACTION_CONFIG` in action-config.js         — the modal's copy
 *
 * A typo in any one of them is caught here rather than in a browser.
 */
class BulkActionCopyTest extends TestCase
{
    use RefreshDatabase;

    #[\PHPUnit\Framework\Attributes\Test]
    public function every_bulk_action_resolves_to_dropdown_and_modal_copy(): void
    {
        foreach ($this->bulkBars() as $where => $bar) {
            // A page may set none of these and lean on the driver's built-in
            // user defaults — the users index does exactly that. Nothing to
            // cross-check in that case.
            $statesRaw = $this->attribute($bar, 'data-bulk-states');

            if ($statesRaw === null) {
                continue;
            }

            $states = json_decode($statesRaw, true);
            $keys = json_decode((string) $this->attribute($bar, 'data-bulk-keys'), true);

            $this->assertIsArray($states, "{$where}: data-bulk-states is not valid JSON: {$statesRaw}");
            $this->assertIsArray($keys, "{$where}: data-bulk-keys is not valid JSON");

            // What the page offers, in the order it offers it.
            $offered = array_values(array_unique(array_merge(...array_values($states))));

            $this->assertNotEmpty($offered, "{$where}: the bar offers no actions at all");

            foreach ($offered as $action) {
                $this->assertArrayHasKey(
                    $action,
                    $keys,
                    "{$where}: [{$action}] is offered but data-bulk-keys has no entry for it"
                );

                // data-bulk-keys maps action -> ACTION_CONFIG key. Absent, the
                // driver falls back to the action name itself, which then fails
                // to resolve for any action that was renamed.
                $configKey = $keys[$action] ?? $action;

                $this->assertStringContainsString(
                    $configKey,
                    $this->actionConfig(),
                    "{$where}: [{$action}] maps to [{$configKey}], which ACTION_CONFIG does not define"
                );

                // actionOptions is a nested literal — `u={delete:{...` — so the
                // key is preceded by `{` or a comma, never by whitespace.
                // Anchoring on whitespace silently matched nothing.
                $this->assertSame(
                    1,
                    preg_match(
                        '/[{,]\s*'.preg_quote($action, '/').':\s*\{|^\s*'.preg_quote($action, '/').':\s*\{/m',
                        $this->actionOptions()
                    ),
                    "{$where}: [{$action}] has no entry in actionOptions — populateDropdown skips it and the dropdown renders empty"
                );
            }

            // And the mixed-state action must be one of them.
            $mixed = $this->attribute($bar, 'data-bulk-mixed');

            if ($mixed !== null) {
                $this->assertContains(
                    $mixed,
                    $offered,
                    "{$where}: data-bulk-mixed is [{$mixed}], which no selection state offers"
                );
            }
        }
    }

    /**
     * Variant agreement, so the colours cannot drift from the design system.
     *
     * design-system.md: `enable => success`, `disable => warning`. A swap here
     * is invisible until someone reads the modal.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function test_the_feature_actions_use_the_mapped_variants (): void
    {
        // design-system.md, Action Color Convention: enable => success,
        // disable => warning. Reversible consequences never wear the
        // irreversible colour.
        foreach (['enable_feature' => 'success', 'disable_feature' => 'warning'] as $action => $variant) {
            $this->assertSame(
                1,
                $this->variantIn($action, $variant, $this->actionConfig()),
                "[{$action}] must be variant `{$variant}` in ACTION_CONFIG per the Action Color Convention"
            );

            $this->assertSame(
                1,
                $this->variantIn($action, $variant, $this->actionOptions()),
                "[{$action}] must be variant `{$variant}` in actionOptions so the dropdown and the modal agree"
            );
        }
    }

    /**
     * Does this JS map give the action the stated variant?
     *
     * Matches only WITHIN the action's own block (`[^}]*`), so a typo in one
     * entry cannot be satisfied by a neighbouring entry's value.
     */
    private function variantIn(string $action, string $variant, string $source): int
    {
        return preg_match(
            '/^\s*'.preg_quote($action, '/').':\s*\{[^}]*variant:\s*\''.preg_quote($variant, '/').'\'/ms',
            $source
        );
    }

    /**
     * Every page with a bulk bar, keyed by route for readable failures.
     *
     * Reads the RENDERED pages rather than the templates: the `data-bulk-states`
     * attribute holds `@json(...)`, which is Blade, not JSON. Parsing the
     * template would assert against a string the browser never sees.
     *
     * Rendered as an admin, since the bar is permission-gated on features.manage
     * (users/roles gate their own bars on the listing permission).
     *
     * @return array<string, string>
     */
    private function bulkBars(): array
    {
        // RefreshDatabase wipes the seeded roles the three routes authorize on.
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $bars = [];

        foreach (['users.index', 'roles.index', 'features.index'] as $route) {
            $response = $this->actingAs($this->admin(), 'web')->get(route($route));

            if ($response->isOk() && str_contains($html = $response->getContent(), 'id="bulkBar"')) {
                $bars[$route] = $html;
            }
        }

        $this->assertNotEmpty($bars, 'no page rendered a bulk bar');

        return $bars;
    }

    private function admin(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find(SystemRole::ADMIN));

        return $user;
    }

    /** The raw `data-*` value, without HTML entities. */
    private function attribute(string $html, string $name): ?string
    {
        if (preg_match('/'.preg_quote($name, '/')."=\s*'([^']*)'/", $html, $m)) {
            return $m[1];
        }

        if (preg_match('/'.preg_quote($name, '/').'=\s*"([^"]*)"/', $html, $m)) {
            return $m[1];
        }

        return null;
    }

    private function actionConfig(): string
    {
        return (string) file_get_contents(resource_path('js/helpers/action-config.js'));
    }

    private function actionOptions(): string
    {
        return (string) file_get_contents(resource_path('js/helpers/bulk-actions.js'));
    }
}
