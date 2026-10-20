<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Response parity — the check `ChannelParityTest` cannot make (P12-D7).
 *
 * The route map proves a capability exists on both channels. It says nothing
 * about whether the two answers carry the same facts, and that is the gap a
 * second transport invites: one channel grows a field, the other keeps the old
 * set, and both look correct from inside their own test file.
 *
 * The contract is declared below rather than inferred, so adding or dropping a
 * resource key fails here until somebody decides which channel it belongs to.
 * Started with users and roles — the two capabilities every client hits first.
 *
 * @see docs/planning/phase-12-api-v1.md § Group D (P12-D7)
 */
class ResponseParityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Facts the web table renders, mapped to where the API carries them.
     *
     * `created_at` is one fact at two precisions: the table renders a date, the
     * resource an ISO timestamp. Same fact, declared difference.
     *
     * @var array<string, array<int, string>>
     */
    private const WEB_FACTS = [
        'users' => ['id', 'name', 'email', 'is_active', 'is_locked', 'created_at'],
        'roles' => ['id', 'name', 'is_system', 'users_count', 'permissions_count'],
    ];

    /**
     * Facts the API adds that the table never shows.
     *
     * Not "extra fields we tolerate" — each one is a decision. A change to this
     * list is a change to the contract on one channel only, which is exactly the
     * drift this test exists to make visible.
     *
     * @var array<string, array<int, string>>
     */
    private const API_ONLY = [
        'users' => ['username', 'email_verified_at', 'must_change_password'],
        'roles' => ['permissions', 'created_at', 'updated_at', 'deleted_at'],
    ];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * A viewer allowed to read both modules on both channels.
     */
    private function admin(): User
    {
        if (! isset($this->admin)) {
            $this->admin = User::factory()->create([
                'name' => 'Parity Admin',
                'email' => 'parity-admin@example.com',
                'is_active' => true,
            ]);
            $this->admin->assignRole(SystemRole::SUPERADMIN);
        }

        return $this->admin;
    }

    /**
     * The same query returns the same rows in the same order on both channels.
     *
     * The rows come from one Action, so a difference here means a controller has
     * started applying its own filter — the failure the shared-Action rule
     * exists to prevent.
     */
    public function test_users_index_returns_the_same_records_on_both_channels(): void
    {
        User::factory()->create(['name' => 'Parity One', 'is_active' => true]);
        User::factory()->create(['name' => 'Parity Two', 'is_active' => true]);
        User::factory()->create(['name' => 'Unrelated', 'is_active' => true]);

        $this->actingAs($this->admin(), 'web');
        $web = $this->get(route('users.index').'?search=Parity')
            ->assertOk()
            ->original['users'];

        Sanctum::actingAs($this->admin());
        $api = $this->getJson(route('api.v1.users.index').'?search=Parity')
            ->assertOk()
            ->json('data.users');

        $this->assertSame(
            collect($web->items())->pluck('id')->all(),
            collect($api)->pluck('id')->all(),
            'the two channels returned different users for the same query'
        );
    }

    /**
     * Every fact the users table renders is in the API row, and the API adds
     * exactly the declared set.
     */
    public function test_users_index_exposes_the_facts_the_web_table_renders(): void
    {
        User::factory()->create([
            'name' => 'Parity Target',
            'email' => 'parity-target@example.com',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin(), 'web');
        $webUser = $this->get(route('users.index', ['search' => 'Parity Target']))
            ->assertOk()
            ->original['users']
            ->items()[0];

        Sanctum::actingAs($this->admin());
        $apiUser = $this->getJson(route('api.v1.users.index', ['search' => 'Parity Target']))
            ->assertOk()
            ->json('data.users.0');

        $this->assertSame((int) $webUser->id, $apiUser['id']);
        $this->assertSame($webUser->name, $apiUser['name']);
        $this->assertSame($webUser->email, $apiUser['email']);
        $this->assertSame($webUser->is_active, $apiUser['is_active']);
        $this->assertSame($webUser->is_locked, $apiUser['is_locked']);
        $this->assertStringStartsWith(
            $webUser->created_at->format('Y-m-d'),
            $apiUser['created_at'],
            'the table and the resource disagree about when the row was created'
        );

        $this->assertSame(
            self::API_ONLY['users'],
            array_values(array_diff(array_keys($apiUser), self::WEB_FACTS['users'])),
            'the user resource grew or lost a field without a parity decision'
        );
    }

    /**
     * Roles: same rows, same counts, same declared extras.
     */
    public function test_roles_index_returns_the_same_facts_on_both_channels(): void
    {
        $this->actingAs($this->admin(), 'web');
        $webRole = $this->get(route('roles.index', ['search' => 'admin']))
            ->assertOk()
            ->original['roles']
            ->items()[0];

        Sanctum::actingAs($this->admin());
        $apiRole = $this->getJson(route('api.v1.roles.index', ['search' => 'admin']))
            ->assertOk()
            ->json('data.roles.0');

        $this->assertSame((int) $webRole->id, $apiRole['id']);
        $this->assertSame($webRole->name, $apiRole['name']);
        $this->assertSame((bool) $webRole->is_system, $apiRole['is_system']);
        $this->assertSame($webRole->users_count, $apiRole['users_count']);
        $this->assertSame($webRole->permissions_count, $apiRole['permissions_count']);

        $this->assertSame(
            self::API_ONLY['roles'],
            array_values(array_diff(array_keys($apiRole), self::WEB_FACTS['roles'])),
            'the role resource grew or lost a field without a parity decision'
        );
    }
}
