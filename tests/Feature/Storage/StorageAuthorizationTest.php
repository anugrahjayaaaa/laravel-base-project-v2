<?php

namespace Tests\Feature\Storage;

use App\Actions\V1\System\SystemSettingsUpdateAction;
use App\Exceptions\StorageCapacityExceededException;
use App\Http\Middleware\VerifyCsrfToken;
use App\Jobs\CleanTemporaryStorageJob;
use App\Jobs\ExecuteSystemBackupJob;
use App\Models\Role;
use App\Models\RoleLookup;
use App\Models\SystemSetting;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Storage\StorageCapacityGuard;
use App\Support\SystemRole;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Pennant\Feature;
use Tests\TestCase;

/**
 * Phase 14 Group D gate: who may reach the storage surface, what the feature
 * flag may and may not switch off, and that the storage audit rows follow the
 * transaction they describe.
 */
class StorageAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->seed(SystemSettingSeeder::class);

        $this->withoutMiddleware(VerifyCsrfToken::class);

        Feature::flushCache();
    }

    private function admin(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find(SystemRole::ADMIN));

        return $user;
    }

    private function superadmin(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find(SystemRole::SUPERADMIN));

        return $user;
    }

    /**
     * `settings.view` without `settings.manage` — the read-only viewer D1
     * singles out. The Storage card is part of the settings form, so its write
     * gate is `settings.manage`, not `storage.manage`.
     */
    private function readOnlySettingsViewer(): User
    {
        $role = Role::create([
            'name' => 'storage-viewer-'.uniqid(),
            'guard_name' => RoleLookup::guard(),
        ]);
        $role->givePermissionTo('settings.view');

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    public function test_a_superadmin_passes_gate_before_on_the_secure_route(): void
    {
        Storage::fake('secure_documents');
        Storage::disk('secure_documents')->put('document.pdf', 'private');

        $url = URL::temporarySignedRoute('storage.secure', now()->addMinute(), ['path' => 'document.pdf']);

        $this->actingAs($this->superadmin())
            ->get($url)
            ->assertOk();
    }

    public function test_a_superadmin_can_queue_a_backup(): void
    {
        Bus::fake([ExecuteSystemBackupJob::class]);

        $this->actingAs($this->superadmin())
            ->post(route('storage.backup.store'))
            ->assertRedirect();

        Bus::assertDispatched(ExecuteSystemBackupJob::class);
    }

    public function test_a_read_only_settings_viewer_cannot_write_storage_keys(): void
    {
        $viewer = $this->readOnlySettingsViewer();

        $this->assertFalse($viewer->can('settings.manage'), 'precondition: the viewer must not hold settings.manage');

        $this->actingAs($viewer)
            ->from('/settings')
            ->post(route('settings.update'), ['storage_warning_threshold_percent' => 90])
            ->assertForbidden();
    }

    /**
     * The flag closes the management surface, not the disk protection. A save
     * posted directly while the flag is off must not change a value the card
     * cannot show.
     */
    public function test_a_storage_key_written_while_the_flag_is_off_is_not_persisted(): void
    {
        Feature::deactivate('storage_management');
        Feature::flushCache();

        // Non-default values, so a reset to the hardcoded default is visible.
        SystemSetting::set('storage_default_disk', 's3');
        SystemSetting::set('backup_schedule', '03:30');
        SystemSetting::bustCache();

        $this->actingAs($this->admin())
            ->from('/settings')
            ->post(route('settings.update'), ['storage_warning_threshold_percent' => 90])
            ->assertRedirect();

        SystemSetting::bustCache();

        $this->assertSame(80, SystemSetting::getInt('storage_warning_threshold_percent', 0), 'the flag-off key was persisted');

        // The hidden cards post nothing, so absent must mean "keep", not
        // "reset to default" — which is what a full-form save used to do to
        // every storage and backup key the strip map did not name.
        $this->assertSame('s3', SystemSetting::getString('storage_default_disk'));
        $this->assertSame('03:30', SystemSetting::getString('backup_schedule'));

        $properties = DB::table('activity_log')->where('event', 'storage_setting.updated')->value('properties');
        $keys = $properties === null ? [] : (json_decode((string) $properties, true)['keys'] ?? []);

        $this->assertNotContains('storage_warning_threshold_percent', $keys);
    }

    public function test_the_disk_binding_still_applies_when_the_flag_is_off(): void
    {
        Feature::deactivate('storage_management');
        Feature::flushCache();

        SystemSetting::set('storage_default_disk', 's3');
        SystemSetting::bustCache();

        AppServiceProvider::bindStorageConfig();

        $this->assertSame('s3', config('filesystems.default'));
    }

    public function test_the_upload_guard_stays_active_when_the_flag_is_off(): void
    {
        Feature::deactivate('storage_management');
        Feature::flushCache();

        SystemSetting::set('storage_max_capacity_mb', '1');
        SystemSetting::bustCache();

        Bus::fake([CleanTemporaryStorageJob::class]);
        Cache::forget('storage.capacity.warning');

        Storage::fake('local');
        Storage::disk('local')->put('large.bin', str_repeat('a', 2097152));

        $this->expectException(StorageCapacityExceededException::class);

        app(StorageCapacityGuard::class)->assertCanStore(0, 'local');
    }

    public function test_the_storage_audit_row_is_discarded_with_its_transaction(): void
    {
        DB::beginTransaction();

        app(SystemSettingsUpdateAction::class)->run(
            ['storage_warning_threshold_percent' => 90],
            partial: true,
            causer: $this->admin(),
        );

        DB::rollBack();

        $this->assertDatabaseMissing('activity_log', ['event' => 'storage_setting.updated']);
    }
}
