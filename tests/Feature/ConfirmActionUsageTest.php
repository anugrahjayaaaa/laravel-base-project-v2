<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Every confirmation trigger goes through <x-ui.confirm-action>.
 *
 * They used to be 22 hand-concatenated attribute strings across three pages,
 * spread over @php closures that returned HTML. That form fails silently: a
 * misspelled data-* just does not match, and the modal falls back to
 * "Confirm" / danger with nothing in the log. These assert the contract
 * instead — the six attributes the driver reads are present on every trigger,
 * and the action-type is a key ACTION_CONFIG actually has.
 */
class ConfirmActionUsageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->app->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['name' => 'Budi Santoso', 'is_active' => true]);
        // The seeded admin role; Role::create() here would collide with the
        // seeder's row for the same (name, guard).
        $admin->assignRole(\App\Models\RoleLookup::find('admin'));

        // Every seeded role is a SYSTEM role, and roles/index.blade.php hides the
        // delete trigger behind `@unless ($role->is_system)` — a system role can
        // never be trashed. Without one custom role the page renders no triggers
        // at all and this test would pass on an empty array, which is exactly the
        // failure it exists to catch.
        if (\App\Models\RoleLookup::find('Custom Role') === null) {
            \App\Models\Role::create([
                'name' => 'Custom Role',
                'guard_name' => \App\Models\RoleLookup::guard(),
            ]);
        }

        return $admin;
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function pages(): array
    {
        return [
            'user index' => ['users.index', 'active', 'delete'],
            'user detail' => ['users.show', 'active', 'delete'],
            'sessions' => ['sessions', 'active', 'logout_all'],
            // Phase 6D: the role pages went ungated by this test because they
            // shipped after it was written. They are where a mistyped
            // data-action-type does the most damage — a delete_role button
            // falling back to a user's delete copy would promise to trash an
            // account instead of a role.
            'roles index' => ['roles.index', 'active', 'delete_role'],
            // The catalogue is read-only: it renders the seeded permissions and
            // offers no mutating trigger at all. The data-provider test still
            // asserts the hand-built-trigger ban covers it, which is the part that
            // matters — nothing on that page may bypass the component.
            'permissions index' => ['permissions.index', 'active', '__none__'],
            // Phase 7A: the feature-flag switch is the only <input> trigger, so
            // it was invisible to a <button>-only scan. Identified by the slug in
            // its URL rather than by an action key — it brings its own copy, so
            // demanding a `data-action-type` here would require the very default
            // the component deliberately refuses to set.
            'features index' => ['features.index', 'active', '__slug__'],
        ];
    }

        #[DataProvider('pages')]
    public function test_every_trigger_carries_the_attributes_the_driver_reads(
        string $page,
        string $status,
        string $expectedType
    ): void {
        $admin = $this->admin();
        // Only the routes that take a parameter get one. Passing $admin to
        // features.index would append it as `?0=Budi...`, which renders fine
        // and hides the fact that the page was never addressed by its own name.
        $url = $page === 'features.index'
            ? route($page)
            : route($page, $admin);
        $html = $this->actingAs($admin)->get($url)->getContent();

        $this->assertSame(
            [],
            $this->handBuiltTriggers(),
            'no page may hand-build a trigger; every one goes through <x-ui.confirm-action>'
        );

        foreach ($this->triggers($html) as $attributes) {
            foreach (['data-bs-toggle', 'data-bs-target', 'data-action', 'data-method', 'data-item-name', 'data-label'] as $required) {
                $this->assertArrayHasKey($required, $attributes, "a trigger is missing {$required}");
            }
            $this->assertSame('modal', $attributes['data-bs-toggle']);
            $this->assertSame('#confirmModal', $attributes['data-bs-target']);

            // A trigger either names an ACTION_CONFIG key or brings its own
            // copy. `action-type` defaults to null precisely because a default
            // here would let an omitted attribute claim somebody else's words —
            // so an absent key is a legitimate branch (the user-form Unlock,
            // the feature switch), not a gap to fail on. What it must NOT do is
            // name a key the config does not have.
            if (array_key_exists('data-action-type', $attributes)) {
                $this->assertContains(
                    $attributes['data-action-type'],
                    array_keys($this->actionConfig()),
                    'data-action-type must be a key ACTION_CONFIG has, or the modal falls back to "Confirm"'
                );
            } else {
                $this->assertArrayHasKey(
                    'data-title',
                    $attributes,
                    'a trigger with no data-action-type must bring its own title, or the modal opens saying "Confirm"'
                );
            }
        }

        // A read-only page is allowed to render no triggers at all.
        if ($expectedType === '__slug__') {
            // Every trigger here is a flag switch, so the assertion is that a
            // switch rendered AT ALL — a page whose toggles silently stopped
            // would otherwise pass on an empty array, which is the failure this
            // test exists to catch.
            $this->assertNotSame(
                [],
                $this->triggers($html),
                'the features page must still offer its per-flag switches'
            );
        } elseif ($expectedType !== '__none__') {
            $this->assertContains(
                $expectedType,
                array_column($this->triggers($html), 'data-action-type'),
                "the {$page} page must still offer {$expectedType}"
            );
        }
    }

    /**
     * The user detail Unlock carries its own copy rather than reading
     * ACTION_CONFIG. Converting it to the component must not have changed the
     * words a user reads, so the legacy branch is still wired.
     */
        public function test_the_user_detail_unlock_keeps_its_own_copy(): void
    {
        $admin = $this->admin();

        // A locked user cannot load their own page — account.state redirects
        // them — so the locked one has to be somebody else in the list.
        $locked = User::factory()->create(['name' => 'Budi Santoso', 'is_locked' => true]);
        $locked->assignRole(\App\Models\RoleLookup::find('admin'));

        $html = $this->actingAs($admin)->get(route('users.show', $locked))->getContent();
        $unlock = collect($this->triggers($html))
            ->first(fn (array $a) => str_contains($a['data-action'] ?? '', '/unlock'));

        $this->assertNotNull($unlock, 'the locked user must still offer Unlock');
        $this->assertArrayNotHasKey(
            'data-action-type',
            $unlock,
            'Unlock supplies its own title/message, so it must not claim a config key'
        );
        $this->assertSame('Unlock User Account?', $unlock['data-title']);
        $this->assertStringContainsString('Budi Santoso', $unlock['data-message']);
        $this->assertSame('success', $unlock['data-variant']);
    }

    /**
     * Both trigger tags, not just <button>.
     *
     * confirm-action grew a `tag` prop so the feature-flag switch could BE an
     * <input type="checkbox">. A <button>-only regex made every input trigger
     * invisible to this test — the switch was never checked at all, which is how
     * the tag prop itself escaped verification. The switch brings its own
     * title/message (no `action-type`), so the data-action-type assertion is
     * skipped for it below rather than made to invent a config key.
     *
     * @return array<int, array<string, string>>
     */
    private function triggers(string $html): array
    {
        preg_match_all('/<(?:button|input)\b[^>]*data-bs-target="#confirmModal"[^>]*>/', $html, $matches);

        return array_map(function (string $tag) {
            preg_match_all('/([\w-]+)="([^"]*)"/', $tag, $pairs, PREG_SET_ORDER);

            return collect($pairs)->mapWithKeys(fn ($p) => [$p[1] => $p[2]])->all();
        }, $matches[0]);
    }

    /**
     * A hand-built trigger has to exist in the source: the component compiles
     * to the same <button>, so the rendered HTML cannot tell the two apart.
     *
     * @return array<int, string>
     */
    private function handBuiltTriggers(): array
    {
        $offenders = [];

        foreach (glob(resource_path('views/{pages,components}/**/*.blade.php'), GLOB_BRACE) ?: [] as $file) {
            if (str_contains((string) file_get_contents($file), 'data-bs-target="#confirmModal"')) {
                $offenders[] = str_replace(resource_path().'/', '', $file);
            }
        }

        return $offenders;
    }

    /**
     * An unknown action key must never borrow another action's copy.
     *
     * Both drivers used to fall back to ACTION_CONFIG.delete, so a typo'd key
     * put "Move X to trash?" on a Lock button with nothing in the log. They now
     * go through resolveAction(), which returns null and warns instead — this
     * fails if a driver ever goes back to a silent fall back.
     */
        public function test_no_driver_borrows_another_actions_copy(): void
    {
        foreach (['confirmation-modal.js', 'bulk-actions.js'] as $driver) {
            $source = (string) file_get_contents(base_path("resources/js/helpers/{$driver}"));

            $this->assertStringNotContainsString(
                '|| ACTION_CONFIG.delete',
                $source,
                "{$driver} falls back to delete's copy for an unknown key"
            );
            $this->assertMatchesRegularExpression(
                '/resolveAction\(/',
                $source,
                "{$driver} must resolve action copy through resolveAction()"
            );
        }
    }

    /**
     * Every key either driver can send must exist, so resolveAction() never has
     * to guess: the bulk dropdown is built from actionOptions, and the triggers
     * are checked by the data-provider test above.
     */
        public function test_every_key_the_bulk_dropdown_can_send_exists(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/helpers/bulk-actions.js'));
        preg_match_all('/var actionOptions = \{(.*?)\n    \};/s', $source, $block);
        preg_match_all('/(\w+):\s*\{/', $block[1][0] ?? '', $keys);

        $this->assertNotEmpty($keys[1], 'could not read actionOptions');

        foreach ($keys[1] as $key) {
            $this->assertArrayHasKey(
                $key,
                $this->actionConfig(),
                "the bulk dropdown offers '{$key}', which ACTION_CONFIG has no copy for"
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function actionConfig(): array
    {
        $js = (string) file_get_contents(base_path('resources/js/helpers/action-config.js'));
        preg_match_all('/^\s{4}(\w+):\s*\{/m', $js, $matches);

        return array_fill_keys($matches[1], true);
    }
}
