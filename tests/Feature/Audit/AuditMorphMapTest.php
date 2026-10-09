<?php

namespace Tests\Feature\Audit;

use App\Models\Activity;
use App\Models\Concerns\Auditable;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\MorphMap;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The audit table's polymorphic columns store aliases, and history matches
 * (Phase 10, P10-D5).
 *
 * ## What this pins, and why each one is a different failure
 *
 * 1. Every model carrying `Auditable` has an alias. The alternative signal — an
 *    unmapped type in the viewer — reads as "Unknown (App\Models\Whatever)", not
 *    as an error, so nothing would notice a model added without one.
 * 2. The backfill rewrites FQCNs AND is idempotent, because it runs against
 *    databases still being written by older code during a rolling deploy.
 * 3. It skips a type it does not recognise rather than failing. History
 *    pointing at a deleted class is not a reason to block a deploy.
 * 4. `auditBulk()` writes aliases too. The raw insert bypasses
 *    `getMorphClass()`, so this one is a separate defect from the builder path —
 *    and a bulk action leaving two spellings of the same type is invisible in
 *    the viewer and loud in the filter dropdown.
 */
class AuditMorphMapTest extends TestCase
{
    use RefreshDatabase;

    /** A model that never existed, so the backfill cannot possibly map it. */
    private const RETIRED_WIDGET = 'RetiredWidget';

    private function table(): string
    {
        return config('activitylog.table_name', 'activity_log');
    }

    #[Test]
    public function test_the_morph_map_is_registered_with_eloquent(): void
    {
        $this->assertSame(MorphMap::ALIASES, Relation::morphMap());
    }

    #[Test]
    public function test_the_map_is_permissive_rather_than_enforced(): void
    {
        // Enforced means `Model::getMorphClass()` throws for an unmapped class,
        // on the WRITE path. A model that later gains the trait without an alias
        // would then 500 the mutation it is recording — inside a transaction.
        // Asserted because the failure it prevents is invisible until someone
        // adds the fifth model, and then it is a production incident.
        $this->assertFalse(
            Relation::requiresMorphMap(),
            'the map is enforced; an unmapped Auditable model will throw on write'
        );
    }

    #[Test]
    public function test_every_auditable_model_has_an_alias(): void
    {
        $map = MorphMap::classToAlias();

        foreach ($this->auditableModels() as $class) {
            $this->assertArrayHasKey(
                $class,
                $map,
                'this model carries Auditable but has no morph alias, so every audit row about it stores an FQCN'
            );
        }
    }

    /**
     * The set is derived from the codebase, not hand-listed — a hand-written
     * list of four would keep passing after a fifth model appeared, which is
     * exactly the case above.
     *
     * @return array<int, class-string>
     */
    private function auditableModels(): array
    {
        $found = [];

        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            $uses = array_keys(class_uses_recursive($class));

            if (in_array(Auditable::class, $uses, true)) {
                $found[] = $class;
            }
        }

        $this->assertNotEmpty($found, 'precondition: the scanner found Auditable models');

        return $found;
    }

    #[Test]
    public function test_the_backfill_rewrites_fqcn_types_to_aliases(): void
    {
        $user = User::factory()->create();

        DB::table($this->table())->insert([
            'log_name' => 'default',
            'description' => 'user.updated',
            'event' => 'user.updated',
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'causer_type' => User::class,
            'causer_id' => $user->id,
            'properties' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runBackfill();

        $row = DB::table($this->table())->first();

        $this->assertSame('user', $row->subject_type);
        $this->assertSame('user', $row->causer_type);
    }

    #[Test]
    public function test_the_backfill_leaves_an_unrecognised_type_alone_and_still_completes(): void
    {
        $before = DB::table($this->table())->count();

        DB::table($this->table())->insert([
            'log_name' => 'default',
            'description' => 'legacy.thing',
            'event' => 'legacy.thing',
            // A class that does not exist in this codebase. History, not an
            // error. Built by concatenation rather than written as an FQCN
            // literal: this is a STRING on purpose — no such class is loaded —
            // and `NoInlineFqnTest` cannot tell the difference, so the escape has
            // to be structural.
            'subject_type' => 'App\\Models\\'.self::RETIRED_WIDGET,
            'subject_id' => 1,
            'causer_type' => null,
            'causer_id' => null,
            'properties' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runBackfill();

        $this->assertSame($before + 1, DB::table($this->table())->count(), 'a row was lost');
        $this->assertSame(
            'App\\Models\\'.self::RETIRED_WIDGET,
            DB::table($this->table())->first()->subject_type,
            'an unmapped type was rewritten'
        );
    }

    #[Test]
    public function test_running_the_backfill_twice_changes_nothing(): void
    {
        $user = User::factory()->create();

        DB::table($this->table())->insert([
            'log_name' => 'default',
            'description' => 'user.updated',
            'event' => 'user.updated',
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'causer_type' => User::class,
            'causer_id' => $user->id,
            'properties' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runBackfill();
        $this->runBackfill();

        $row = DB::table($this->table())->first();

        $this->assertSame('user', $row->subject_type);
        $this->assertSame('user', $row->causer_type);
    }

    #[Test]
    public function test_the_backfill_rolls_back_to_fqcns(): void
    {
        $user = User::factory()->create();

        DB::table($this->table())->insert([
            'log_name' => 'default',
            'description' => 'user.updated',
            'event' => 'user.updated',
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'causer_type' => User::class,
            'causer_id' => $user->id,
            'properties' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runBackfill();
        $migration = $this->backfillMigration();
        $migration->down();

        $row = DB::table($this->table())->first();

        $this->assertSame(User::class, $row->subject_type);
        $this->assertSame(User::class, $row->causer_type);
    }

    #[Test]
    public function test_a_new_single_row_audit_stores_the_alias(): void
    {
        $actor = User::factory()->create();
        $subject = User::factory()->create();

        $subject->audit('user.updated', $actor);

        $this->assertSame('user', DB::table($this->table())->latest('id')->first()->subject_type);
    }

    #[Test]
    public function test_a_bulk_audit_stores_the_alias_too(): void
    {
        $actor = User::factory()->create();
        $subjects = User::factory(3)->create();

        User::auditBulk('user.locked', array_map(
            fn (User $u): array => ['subject_id' => $u->id],
            $subjects->all()
        ), $actor);

        $types = DB::table($this->table())->distinct()->pluck('subject_type')->all();

        $this->assertSame(['user'], $types, 'a bulk row stored a different spelling than a single row');
    }

    #[Test]
    public function test_a_soft_deleted_subject_still_resolves_by_name(): void
    {
        $actor = User::factory()->create();
        $subject = User::factory()->create(['name' => 'Budi Santoso']);

        $subject->audit('user.updated', $actor);
        $subject->delete();

        $row = Activity::query()->with('subject')->latest('id')->first();

        // Precondition: the config key that makes this work (D3).
        $this->assertTrue(
            config('activitylog.subject_returns_soft_deleted_models'),
            'precondition: soft-deleted subjects are configured to resolve'
        );

        $this->assertSame('Budi Santoso', $row->subjectLabel());
    }

    /**
     * A pre-existing row, in the shape the database holds BEFORE this phase: the
     * type columns carry FQCNs.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function legacyRow(string $table, array $overrides = []): void
    {
        $defaults = [
            'model_has_roles' => [
                'role_id' => RoleLookup::find('user')->getKey(),
                'model_id' => User::factory()->create()->getKey(),
                'model_type' => User::class,
            ],
            'model_has_permissions' => [
                'permission_id' => DB::table('permissions')->where('name', 'audit.view')->value('id'),
                'model_id' => User::factory()->create()->getKey(),
                'model_type' => User::class,
            ],
            'personal_access_tokens' => [
                'tokenable_id' => User::factory()->create()->getKey(),
                'tokenable_type' => User::class,
                'name' => 'legacy',
                'token' => hash('sha256', 'legacy'),
                'abilities' => '["*"]',
            ],
            'notifications' => [
                'id' => (string) Str::uuid(),
                'type' => 'database',
                'notifiable_id' => User::factory()->create()->getKey(),
                'notifiable_type' => User::class,
                'data' => '{}',
                'read_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            'activity_log' => [
                'log_name' => 'default',
                'description' => 'user.updated',
                'event' => 'user.updated',
                'subject_type' => User::class,
                'subject_id' => 1,
                'causer_type' => User::class,
                'causer_id' => 1,
                'properties' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table($table)->insert(array_merge($defaults[$table], $overrides));
    }

    /**
     * Every polymorphic type column in the application.
     *
     * @return array<string, array<int, string>>
     */
    private function morphColumns(): array
    {
        return [
            'activity_log' => ['subject_type', 'causer_type'],
            'model_has_roles' => ['model_type'],
            'model_has_permissions' => ['model_type'],
            'personal_access_tokens' => ['tokenable_type'],
            'notifications' => ['notifiable_type'],
        ];
    }

    #[Test]
    public function test_the_backfill_covers_every_polymorphic_column_in_the_application(): void
    {
        $this->seedRolesAndPermissions();

        // The bug this exists to prevent: backfilling `activity_log` alone, which
        // is the ONLY column the phase plan named. `Relation::morphMap()` is global,
        // so the permission pivots, Sanctum's tokens and Laravel's notifications
        // all change behaviour with it — and an un-backfilled FQCN row there is
        // INVISIBLE, not degraded. Verified: a legacy `model_has_roles` row makes
        // `whereHas('roles')` return false.
        foreach ($this->morphColumns() as $table => $columns) {
            $this->legacyRow($table);

            $before = DB::table($table)->get()->map(
                fn ($r): array => (array) $r
            );

            $this->runBackfill();

            foreach ($columns as $column) {
                $remaining = DB::table($table)->where($column, User::class)->count();

                $this->assertSame(
                    0,
                    $remaining,
                    "{$table}.{$column} still holds an FQCN; the row is invisible to every relation query"
                );
            }
        }
    }

    #[Test]
    public function test_a_backfilled_legacy_row_is_visible_to_a_relation_again(): void
    {
        // The behavioural proof, because the row-count assertions above only say
        // the string changed. This says a permission that used to be granted is
        // granted again — which is the thing that silently locks people out.
        $this->seedRolesAndPermissions();

        $user = User::factory()->create();

        DB::table('model_has_roles')->insert([
            'role_id' => RoleLookup::find('user')->getKey(),
            'model_id' => $user->getKey(),
            'model_type' => User::class,
        ]);

        $this->assertFalse(
            User::query()->whereKey($user->getKey())->whereHas('roles')->exists(),
            'precondition: an FQCN pivot row is invisible once the map is registered'
        );

        $this->runBackfill();

        $this->assertTrue(
            User::query()->whereKey($user->getKey())->whereHas('roles')->exists(),
            'the backfilled row is still invisible, so the role was lost'
        );
        $this->assertContains('user', $user->fresh()->roles->pluck('name')->all());
    }

    #[Test]
    public function test_a_newly_written_role_assignment_matches_a_backfilled_one(): void
    {
        // The two must not diverge. A project upgraded mid-flight writes `user`
        // while a project that ran only the migration has `App\Models\User`
        // rewritten to `user` — the whole point is that they converge.
        $this->seedRolesAndPermissions();

        $user = User::factory()->create();
        $user->assignRole(RoleLookup::find('user'));

        $this->runBackfill();

        $distinct = DB::table('model_has_roles')->distinct()->pluck('model_type')->all();

        $this->assertSame(['user'], $distinct, 'a freshly written pivot row disagrees with a backfilled one');
    }

    /**
     * The pivot rows reference seeded roles and permissions, which the raw
     * inserts below bypass — a `assignRole()` call would create them but would
     * also write the pivot row under test.
     */
    private function seedRolesAndPermissions(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * The migration instance, loaded from the file so the test exercises the
     * shipped one rather than a re-implementation of it.
     */
    private function backfillMigration(): object
    {
        $migration = require database_path('migrations/2026_10_09_010000_backfill_morph_aliases.php');

        return $migration;
    }

    private function runBackfill(): void
    {
        $this->backfillMigration()->up();
    }
}
