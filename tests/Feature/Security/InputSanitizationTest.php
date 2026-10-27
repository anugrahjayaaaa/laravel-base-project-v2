<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * P13 Group D — input sanitization and mass-assignment shielding, web side.
 *
 * The other probes in this folder cover the API and the notification bodies
 * (`SecurityAuditTest`, `NotificationPentestTest`, `AuditPentestTest`,
 * `RbacPentestTest`). This file pins the gaps those leave:
 *
 *   - users index and show must ESCAPE a stored hostile name, not merely store
 *     it safely (the existing tests only prove `strip_tags` at write time);
 *   - `is_locked`, `must_change_password`, `password_expires_at` and `roles`
 *     riding along on a profile save or an admin update are ignored, because
 *     only `validated()` keys reach the Action;
 *   - an unknown `sort`/`direction` on the users index is refused on the web
 *     too, not only on the API;
 *   - a query key nobody declared never reaches the query builder.
 */
class InputSanitizationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
        ]);
        $this->admin->assignRole(SystemRole::SUPERADMIN);
    }

    /**
     * A hostile name is stored by a caller that never passed this app's write
     * path (an import, a seeder, a direct DB write) and rendered by two pages
     * that read it back. `{{ }}` is the only thing standing between it and the
     * reader, so the assertion is on the rendered bytes.
     */
    public function test_users_index_and_show_escape_a_stored_hostile_name(): void
    {
        $payload = '<script>alert("name")</script>';

        $hostile = User::factory()->create([
            'name' => $payload,
            'email' => 'hostile@example.com',
        ]);

        $this->actingAs($this->admin, 'web');

        $index = $this->get(route('users.index'))->assertOk()->getContent();

        $this->assertStringContainsString('&lt;script&gt;alert(&quot;name&quot;)&lt;/script&gt;', $index);
        $this->assertStringNotContainsString($payload, $index);

        $show = $this->get(route('users.show', $hostile))->assertOk()->getContent();

        $this->assertStringContainsString('&lt;script&gt;alert(&quot;name&quot;)&lt;/script&gt;', $show);
        $this->assertStringNotContainsString($payload, $show);
    }

    /**
     * `ProfileUpdateRequest` declares no rule for any column that decides
     * whether an account may log in. `validated()` is the boundary, so four
     * keys that look like they belong are dropped rather than written.
     */
    public function test_profile_update_ignores_privilege_columns(): void
    {
        $user = User::factory()->create(['name' => 'Old Name']);
        $this->actingAs($user, 'web');

        $tomorrow = now()->addDay();

        $this->put(route('profile.update'), [
            'name' => 'New Name',
            'is_locked' => true,
            'must_change_password' => true,
            'password_expires_at' => $tomorrow->toDateTimeString(),
            'roles' => [SystemRole::SUPERADMIN],
        ])->assertRedirect(route('profile.show'));

        $user->refresh();

        $this->assertSame('New Name', $user->name);
        $this->assertFalse((bool) $user->is_locked);
        $this->assertFalse((bool) $user->must_change_password);
        $this->assertNull($user->password_expires_at);
        $this->assertFalse($user->hasRole(SystemRole::SUPERADMIN));
    }

    /**
     * The admin edit form sends a fixed set. Anything else arrives only because
     * a client added it, and must not reach columns `UserActivateAction`,
     * `UserLockAction` and `RoleAssignAction` own.
     */
    public function test_admin_user_update_ignores_sensitive_columns(): void
    {
        $target = User::factory()->create(['name' => 'Target User']);
        $this->actingAs($this->admin, 'web');

        $this->put(route('users.update', $target), [
            'name' => 'Renamed By Admin',
            'status' => 'active',
            'is_locked' => true,
            'must_change_password' => true,
            'password_expires_at' => now()->addDay()->toDateTimeString(),
        ])->assertRedirect();

        $target->refresh();

        $this->assertSame('Renamed By Admin', $target->name);
        $this->assertFalse((bool) $target->is_locked);
        $this->assertFalse((bool) $target->must_change_password);
        $this->assertNull($target->password_expires_at);
    }

    /**
     * `sort` reaches `orderBy()` as an identifier, which cannot be bound as a
     * value. The web index therefore refuses it the same way the API answers
     * 422 — a redirect with a field error, and the table still standing.
     */
    public function test_users_index_refuses_an_unknown_sort_and_direction(): void
    {
        $this->actingAs($this->admin, 'web');

        $this->get(route('users.index', [
            'sort' => 'name;DROP TABLE users--',
            'direction' => 'sideways',
        ]))
            ->assertRedirect()
            ->assertSessionHasErrors(['sort', 'direction']);

        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(User::query()->whereKey($this->admin->getKey())->exists());
    }

    /**
     * A key nobody declared is not a validation error — it is absent from
     * `validated()` and never becomes SQL. Asserting on the query log rather
     * than on the response is what makes this about the builder, not the view.
     */
    public function test_an_undeclared_query_key_never_reaches_the_query_builder(): void
    {
        $payload = "'; DROP TABLE users;--";
        $this->actingAs($this->admin, 'web');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get(route('users.index', [
            'search' => 'Admin',
            'unknown_filter' => $payload,
        ]))->assertOk();

        $sql = $this->loggedSql();

        DB::disableQueryLog();

        $this->assertStringNotContainsString('DROP TABLE users', $sql);
        $this->assertStringNotContainsString($payload, $sql);
    }

    /**
     * The inbox has no sort or filter surface at all: it takes a `Request`, not
     * a query Request, so nothing in the URL is read. Pinned as "the payload is
     * inert", not "the payload is rejected" — the endpoint does not claim to
     * validate keys it never reads.
     */
    public function test_notification_inbox_ignores_sort_and_filter_keys(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get(route('notifications.inbox', [
            'sort' => 'data;DROP TABLE notifications--',
            'direction' => 'sideways',
            'unknown_filter' => 'evil-payload',
        ]))->assertOk();

        $sql = $this->loggedSql();

        DB::disableQueryLog();

        $this->assertStringNotContainsString('DROP TABLE notifications', $sql);
        $this->assertStringNotContainsString('evil-payload', $sql);
        $this->assertTrue(Schema::hasTable('notifications'));
    }

    /**
     * Query text and bound values in one string — a payload that survives as a
     * binding is still inert, but a test that only read `query` could not tell
     * the two apart and would pass on a payload it never saw.
     */
    private function loggedSql(): string
    {
        return collect(DB::getQueryLog())
            ->map(fn (array $entry): string => $entry['query'].' ['.implode(', ', array_map(
                fn (mixed $binding): string => is_scalar($binding) ? (string) $binding : get_debug_type($binding),
                $entry['bindings'],
            )).']')
            ->implode("\n");
    }
}
