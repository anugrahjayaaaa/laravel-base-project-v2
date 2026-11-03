<?php

namespace Tests\Feature\Storage;

use App\Exceptions\StorageCapacityExceededException;
use App\Http\Middleware\VerifyCsrfToken;
use App\Jobs\CleanTemporaryStorageJob;
use App\Models\RoleLookup;
use App\Models\SystemSetting;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Storage\StorageCapacityGuard;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Pennant\Feature;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Tests\TestCase;

/**
 * Phase 14 Group A gate: the disk an operator chooses is the disk the app uses,
 * the credentials stay encrypted, and the capacity guard refuses before the
 * write rather than after it.
 */
class StorageDiskTest extends TestCase
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
        $user->assignRole(RoleLookup::find('admin'));

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function storagePayload(array $overrides = []): array
    {
        return array_merge([
            'storage_default_disk' => 's3',
            'storage_s3_key' => 'AKIAEXAMPLE',
            'storage_s3_secret' => 'super-secret-value',
            'storage_s3_region' => 'us-east-1',
            'storage_s3_bucket' => 'base-project',
        ], $overrides);
    }

    /**
     * The card offers S3 as a driver, so the adapter package must be present.
     * laravel/framework only suggests it: without it the first operation on a
     * disk the admin just selected throws a class-not-found.
     */
    public function test_the_s3_driver_package_is_installed(): void
    {
        config([
            'filesystems.disks.s3.bucket' => 'base-project',
            'filesystems.disks.s3.region' => 'us-east-1',
        ]);

        $this->assertInstanceOf(AwsS3V3Adapter::class, Storage::disk('s3')->getAdapter());
    }

    public function test_default_disk_resolves_to_local_without_a_setting(): void
    {
        SystemSetting::query()->where('key', 'storage_default_disk')->delete();
        SystemSetting::bustCache();

        AppServiceProvider::bindStorageConfig();

        $this->assertSame('local', config('filesystems.default'));
    }

    /**
     * A boot that runs before the settings table exists (`migrate`, first
     * install) must keep the config it was given, not throw: a failed read
     * means "use config", never "app cannot boot".
     */
    public function test_boot_keeps_the_config_when_the_settings_table_is_missing(): void
    {
        config(['filesystems.default' => 's3']);
        Schema::drop('system_settings');
        SystemSetting::bustCache();

        AppServiceProvider::bindStorageConfig();

        $this->assertSame('s3', config('filesystems.default'));
    }

    public function test_stored_disk_rebinds_the_runtime_config(): void
    {
        SystemSetting::set('storage_default_disk', 's3');
        SystemSetting::bustCache();

        AppServiceProvider::bindStorageConfig();

        $this->assertSame('s3', config('filesystems.default'));
    }

    public function test_local_root_and_permissions_rebind_the_local_disk(): void
    {
        $root = storage_path('app/custom-private');

        SystemSetting::set('storage_local_root', $root);
        SystemSetting::set('storage_local_permission', '0600');
        SystemSetting::bustCache();

        AppServiceProvider::bindStorageConfig();

        $this->assertSame($root, config('filesystems.disks.local.root'));
        $this->assertSame(0600, config('filesystems.disks.local.permissions.file'));
        $this->assertSame(0700, config('filesystems.disks.local.permissions.dir'));
    }

    public function test_s3_credentials_are_stored_encrypted_and_never_returned(): void
    {
        $this->actingAs($this->admin())
            ->from('/settings')
            ->post(route('settings.update'), $this->storagePayload());

        $stored = DB::table('system_settings')->where('key', 'storage_s3_secret')->value('value');

        $this->assertNotSame('super-secret-value', $stored);
        $this->assertSame('super-secret-value', decrypt($stored));

        // The read paths that feed the table, the API and the form.
        $this->assertArrayNotHasKey('storage_s3_secret', SystemSetting::getAll());
        $this->assertArrayNotHasKey('storage_s3_secret', SystemSetting::getLive());
    }

    public function test_the_settings_audit_row_never_contains_the_credential(): void
    {
        $this->actingAs($this->admin())
            ->from('/settings')
            ->post(route('settings.update'), $this->storagePayload());

        $properties = (string) DB::table('activity_log')
            ->where('event', 'system_setting.updated')
            ->value('properties');

        $this->assertStringNotContainsString('super-secret-value', $properties);
        $this->assertDatabaseHas('activity_log', ['event' => 'storage_setting.updated']);
    }

    public function test_a_save_that_changes_no_storage_value_writes_no_storage_audit_row(): void
    {
        $this->actingAs($this->admin())
            ->from('/settings')
            ->post(route('settings.update'), $this->storagePayload());

        $this->assertDatabaseHas('activity_log', ['event' => 'storage_setting.updated']);

        DB::table('activity_log')->where('event', 'storage_setting.updated')->delete();

        // Same payload twice: every storage field is posted on every full-form
        // save, so a presence check would claim a change here. The credential is
        // re-encrypted with a fresh IV each time, which is why the comparison
        // has to happen on plaintext.
        $this->actingAs($this->admin())
            ->from('/settings')
            ->post(route('settings.update'), $this->storagePayload());

        $this->assertDatabaseMissing('activity_log', ['event' => 'storage_setting.updated']);
    }

    public function test_the_storage_audit_row_names_only_the_keys_that_changed(): void
    {
        $this->actingAs($this->admin())
            ->from('/settings')
            ->post(route('settings.update'), ['storage_warning_threshold_percent' => 90]);

        $properties = (string) DB::table('activity_log')
            ->where('event', 'storage_setting.updated')
            ->value('properties');

        $this->assertSame(
            ['storage_warning_threshold_percent'],
            json_decode($properties, true)['keys'],
        );
    }

    public function test_switching_to_s3_requires_credentials(): void
    {
        $this->actingAs($this->admin())
            ->from('/settings')
            ->post(route('settings.update'), [
                'storage_default_disk' => 's3',
                'storage_s3_region' => 'us-east-1',
                'storage_s3_bucket' => 'base-project',
            ])
            ->assertSessionHasErrors(['storage_s3_key', 'storage_s3_secret']);
    }

    public function test_a_blank_secret_keeps_the_stored_one_when_switching_back(): void
    {
        $this->actingAs($this->admin())
            ->from('/settings')
            ->post(route('settings.update'), $this->storagePayload());

        SystemSetting::bustCache();
        $ciphertext = DB::table('system_settings')->where('key', 'storage_s3_secret')->value('value');

        $this->actingAs($this->admin())
            ->from('/settings')
            ->post(route('settings.update'), $this->storagePayload(['storage_s3_secret' => '']));

        $this->assertSame(
            $ciphertext,
            DB::table('system_settings')->where('key', 'storage_s3_secret')->value('value'),
            'A blank credential field must keep the stored ciphertext.'
        );
    }

    public function test_the_local_root_must_stay_inside_the_storage_tree(): void
    {
        $this->actingAs($this->admin())
            ->from('/settings')
            ->post(route('settings.update'), [
                'storage_default_disk' => 'local',
                'storage_local_root' => '/etc',
            ])
            ->assertSessionHasErrors('storage_local_root');
    }

    /**
     * A prefix comparison accepts this: the string starts with storage_path()
     * and still resolves outside it.
     */
    public function test_the_local_root_rejects_a_parent_directory_escape(): void
    {
        $this->actingAs($this->admin())
            ->from('/settings')
            ->post(route('settings.update'), [
                'storage_default_disk' => 'local',
                'storage_local_root' => storage_path().'/../etc',
            ])
            ->assertSessionHasErrors('storage_local_root');
    }

    /**
     * Same hole through the filesystem: the string sits inside the tree, the
     * link does not.
     */
    public function test_the_local_root_rejects_a_symlink_out_of_the_tree(): void
    {
        $outside = sys_get_temp_dir().'/lbp-storage-'.uniqid();
        File::ensureDirectoryExists($outside);

        $link = storage_path('app/escape-'.uniqid());
        File::link($outside, $link);

        try {
            $this->actingAs($this->admin())
                ->from('/settings')
                ->post(route('settings.update'), [
                    'storage_default_disk' => 'local',
                    'storage_local_root' => $link.'/files',
                ])
                ->assertSessionHasErrors('storage_local_root');
        } finally {
            File::delete($link);
            File::deleteDirectory($outside);
        }
    }

    public function test_a_local_root_inside_the_storage_tree_is_accepted(): void
    {
        $root = storage_path('app/private/custom-'.uniqid());

        $this->actingAs($this->admin())
            ->from('/settings')
            ->post(route('settings.update'), [
                'storage_default_disk' => 'local',
                'storage_local_root' => $root,
            ])
            ->assertSessionHasNoErrors();

        SystemSetting::bustCache();

        $this->assertSame($root, SystemSetting::getString('storage_local_root'));
    }

    public function test_the_capacity_guard_refuses_a_write_over_the_quota(): void
    {
        // Sabotage check for P14-A5: delete the throw in assertCanStore and
        // this test goes green for the wrong reason.
        SystemSetting::set('storage_max_capacity_mb', '1');
        SystemSetting::bustCache();

        Bus::fake([CleanTemporaryStorageJob::class]);
        Cache::forget('storage.capacity.warning');

        Storage::fake('local');
        Storage::disk('local')->put('large.bin', str_repeat('a', 2097152));

        $guard = app(StorageCapacityGuard::class);
        $metrics = $guard->metrics('local');

        $this->assertTrue($metrics['known'], 'a local disk must report its size');
        $this->assertGreaterThan(1048576, $metrics['used']);

        try {
            $guard->assertCanStore(0, 'local');
            $this->fail('The guard accepted a write on a disk already past its quota.');
        } catch (StorageCapacityExceededException $e) {
            $this->assertSame('local', $e->metrics['disk']);
        }

        $this->assertDatabaseHas('activity_log', ['event' => 'storage.capacity_warning']);
        $this->assertSame(
            'SYSTEM',
            json_decode((string) DB::table('activity_log')->where('event', 'storage.capacity_warning')->value('properties'), true)['causer'] ?? null,
            'a crossing detected with no logged-in actor is the guard acting, not an anonymous person'
        );
        Bus::assertDispatched(CleanTemporaryStorageJob::class);
    }

    public function test_the_capacity_warning_is_throttled_to_once_per_hour(): void
    {
        SystemSetting::set('storage_max_capacity_mb', '1');
        SystemSetting::bustCache();

        Bus::fake([CleanTemporaryStorageJob::class]);
        Cache::forget('storage.capacity.warning');

        Storage::fake('local');
        Storage::disk('local')->put('large.bin', str_repeat('a', 2097152));

        $guard = app(StorageCapacityGuard::class);

        try {
            $guard->assertCanStore(0, 'local');
        } catch (StorageCapacityExceededException) {
            // expected
        }

        try {
            $guard->assertCanStore(0, 'local');
        } catch (StorageCapacityExceededException) {
            // expected — refusal every time, warning only once
        }

        Bus::assertDispatchedTimes(CleanTemporaryStorageJob::class, 1);
        $this->assertSame(1, DB::table('activity_log')->where('event', 'storage.capacity_warning')->count());
    }

    public function test_the_capacity_guard_allows_writes_with_no_quota(): void
    {
        SystemSetting::set('storage_max_capacity_mb', '0');
        SystemSetting::bustCache();

        app(StorageCapacityGuard::class)->assertCanStore(0, 'local');

        $this->assertTrue(true, 'no exception means the unlimited disk accepted the write');
    }

    public function test_the_capacity_guard_measures_used_bytes_only_under_the_storage_root(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('capacity-probe.txt', str_repeat('a', 2048));

        $metrics = app(StorageCapacityGuard::class)->metrics('local');

        $this->assertTrue($metrics['known']);
        $this->assertSame(2048, $metrics['used']);

        Storage::disk('local')->delete('capacity-probe.txt');
    }

    public function test_the_display_gauge_reuses_a_measurement_while_the_guard_reads_fresh(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('gauge-a.txt', str_repeat('a', 2048));

        $guard = app(StorageCapacityGuard::class);
        $displayed = $guard->cachedMetrics('local');

        Storage::disk('local')->put('gauge-b.txt', str_repeat('b', 1024));

        $this->assertSame(
            $displayed['used'],
            $guard->cachedMetrics('local')['used'],
            'the gauge reuses its walk for a minute instead of walking on every poll'
        );
        $this->assertSame(
            3072,
            $guard->metrics('local')['used'],
            'the guard must never read the cached figure — a minute-old number can pass a full disk'
        );

        $this->travel(61)->seconds();

        $this->assertSame(3072, $guard->cachedMetrics('local')['used']);
    }

    public function test_resubmitting_an_unchanged_secret_does_not_rewrite_its_ciphertext(): void
    {
        $payload = $this->storagePayload();

        $this->actingAs($this->admin())
            ->from('/settings')
            ->post(route('settings.update'), $payload)
            ->assertSessionHasNoErrors();

        $first = DB::table('system_settings')->where('key', 'storage_s3_secret')->value('value');

        // `encrypt()` uses a fresh IV, so every save rewrites the row with
        // different ciphertext that decrypts to the same secret.
        $this->actingAs($this->admin())
            ->from('/settings')
            ->post(route('settings.update'), $payload)
            ->assertSessionHasNoErrors();

        $this->assertSame(
            $first,
            DB::table('system_settings')->where('key', 'storage_s3_secret')->value('value'),
            'an unchanged credential must not be re-encrypted'
        );
        $this->assertSame('super-secret-value', decrypt($first));
    }

    public function test_storage_full_renders_the_documented_json_envelope(): void
    {
        config(['app.debug' => false]);

        Route::get('/api/v1/contract/storage-full', fn () => throw new StorageCapacityExceededException([
            'disk' => 'local',
            'used' => 100,
            'total' => 100,
            'percent' => 100.0,
        ]));

        $this->getJson('/api/v1/contract/storage-full')
            ->assertStatus(507)
            ->assertJsonPath('code', 'STORAGE_FULL')
            ->assertJsonPath('meta.storage.disk', 'local')
            ->assertJsonStructure([
                'message',
                'code',
                'meta' => ['request_id', 'timestamp', 'storage'],
            ])
            ->assertJsonMissingPath('debug');
    }

    public function test_the_storage_card_renders_only_when_the_flag_is_on(): void
    {
        $this->actingAs($this->admin())
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Storage Management')
            ->assertSee('storage_default_disk', false)
            ->assertSee('id="storage-local-fields"', false)
            ->assertSee('id="storage-s3-fields"', false)
            ->assertSee('syncStorageFields', false);

        Feature::deactivate('storage_management');
        Feature::flushCache();

        $this->actingAs($this->admin())
            ->get(route('settings.index'))
            ->assertOk()
            ->assertDontSee('Storage Management');
    }

    public function test_a_stored_secret_is_never_echoed_into_the_settings_html(): void
    {
        $this->actingAs($this->admin())
            ->from('/settings')
            ->post(route('settings.update'), $this->storagePayload())
            ->assertRedirect();

        $this->actingAs($this->admin())
            ->get(route('settings.index'))
            ->assertOk()
            ->assertDontSee('super-secret-value')
            ->assertSee('Leave blank to keep the stored value');
    }

    public function test_the_secure_route_refuses_an_unsigned_request(): void
    {
        $this->actingAs($this->admin())
            ->get(route('storage.secure', ['path' => 'document.pdf']))
            ->assertForbidden();
    }

    public function test_a_signed_url_serves_the_file_and_expires(): void
    {
        Storage::fake('secure_documents');
        Storage::disk('secure_documents')->put('document.pdf', 'private');

        $url = URL::temporarySignedRoute('storage.secure', now()->addMinute(), ['path' => 'document.pdf']);

        $this->actingAs($this->admin())->get($url)->assertOk();

        $expired = URL::temporarySignedRoute('storage.secure', now()->subMinute(), ['path' => 'document.pdf']);

        $this->actingAs($this->admin())->get($expired)->assertForbidden();
    }

    public function test_the_secure_route_refuses_traversal(): void
    {
        Storage::fake('secure_documents');

        $url = URL::temporarySignedRoute('storage.secure', now()->addMinute(), ['path' => '../.env']);

        $this->actingAs($this->admin())->get($url)->assertNotFound();
    }

    public function test_the_secure_route_refuses_without_storage_view(): void
    {
        Storage::fake('secure_documents');
        Storage::disk('secure_documents')->put('document.pdf', 'private');

        $user = User::factory()->create(['email_verified_at' => now()]);
        $url = URL::temporarySignedRoute('storage.secure', now()->addMinute(), ['path' => 'document.pdf']);

        $this->actingAs($user)->get($url)->assertForbidden();
    }

    public function test_the_secure_route_is_off_when_the_flag_is_off(): void
    {
        Feature::deactivate('storage_management');
        Feature::flushCache();

        // Even a valid signature cannot pass the flag middleware.
        Storage::fake('secure_documents');
        Storage::disk('secure_documents')->put('document.pdf', 'private');

        $url = URL::temporarySignedRoute('storage.secure', now()->addMinute(), ['path' => 'document.pdf']);

        $this->actingAs($this->admin())->get($url)->assertForbidden();
    }

    public function test_a_missing_secure_file_is_not_found(): void
    {
        Storage::fake('secure_documents');

        $url = URL::temporarySignedRoute('storage.secure', now()->addMinute(), ['path' => 'missing.pdf']);

        $this->actingAs($this->admin())->get($url)->assertNotFound();
    }
}
