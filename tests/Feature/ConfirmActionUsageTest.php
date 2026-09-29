<?php

namespace Tests\Feature;

use App\Models\RoleLookup;
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
        ];
    }

    #[Test]
    #[DataProvider('pages')]
    public function test_every_trigger_carries_the_attributes_the_driver_reads(
        string $page,
        string $status,
        string $expectedType
    ): void {
        $admin = $this->admin();
        $html = $this->actingAs($admin)->get(route($page, $admin))->getContent();

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
            $this->assertContains(
                $attributes['data-action-type'] ?? '',
                array_keys($this->actionConfig()),
                'data-action-type must be a key ACTION_CONFIG has, or the modal falls back to "Confirm"'
            );
        }

        $this->assertContains(
            $expectedType,
            array_column($this->triggers($html), 'data-action-type'),
            "the {$page} page must still offer {$expectedType}"
        );
    }

    /**
     * The user detail Unlock carries its own copy rather than reading
     * ACTION_CONFIG. Converting it to the component must not have changed the
     * words a user reads, so the legacy branch is still wired.
     */
    #[Test]
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
     * @return array<int, string>
     */
    private function triggers(string $html): array
    {
        preg_match_all('/<button\b[^>]*data-bs-target="#confirmModal"[^>]*>/', $html, $matches);

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

        foreach (glob(resource_path('views/pages/**/*.blade.php')) ?: [] as $file) {
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
    #[Test]
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
    #[Test]
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
