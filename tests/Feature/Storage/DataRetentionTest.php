<?php

namespace Tests\Feature\Storage;

use App\Http\Middleware\VerifyCsrfToken;
use App\Jobs\CleanTemporaryStorageJob;
use App\Models\RoleLookup;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\QueueName;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;
use Tests\TestCase;

/**
 * Phase 14 Group C gate: only `tmp` is cleaned, only past the TTL, and the two
 * records that must survive every retention job — the audit trail and
 * soft-deleted models — are asserted untouched.
 */
class DataRetentionTest extends TestCase
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

        Storage::fake('tmp');

        Feature::flushCache();
    }

    private function admin(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find('admin'));

        return $user;
    }

    /**
     * Backdate a file's mtime, which is what the job compares against the TTL.
     * `Storage::fake` writes real files, so `touch` reaches the same path the
     * disk's `lastModified()` reads.
     */
    private function age(string $path, int $hours): void
    {
        touch(Storage::disk('tmp')->path($path), now()->subHours($hours)->getTimestamp());
    }

    public function test_only_tmp_files_past_the_ttl_are_deleted(): void
    {
        Storage::disk('tmp')->put('old.txt', 'old');
        Storage::disk('tmp')->put('fresh.txt', 'fresh');
        $this->age('old.txt', 25);

        (new CleanTemporaryStorageJob())->handle();

        Storage::disk('tmp')->assertMissing('old.txt');
        Storage::disk('tmp')->assertExists('fresh.txt');
    }

    public function test_the_retention_window_comes_from_the_setting(): void
    {
        SystemSetting::set('temp_file_retention_hours', '1');
        SystemSetting::bustCache();

        Storage::disk('tmp')->put('two-hours-old.txt', 'old');
        Storage::disk('tmp')->put('half-hour-old.txt', 'fresh');
        $this->age('two-hours-old.txt', 2);
        $this->age('half-hour-old.txt', 0);

        (new CleanTemporaryStorageJob())->handle();

        Storage::disk('tmp')->assertMissing('two-hours-old.txt');
        Storage::disk('tmp')->assertExists('half-hour-old.txt');
    }

    public function test_a_file_exactly_at_the_ttl_boundary_is_kept(): void
    {
        $this->travelTo(now());

        Storage::disk('tmp')->put('boundary.txt', 'x');
        $this->age('boundary.txt', 24);

        (new CleanTemporaryStorageJob())->handle();

        Storage::disk('tmp')->assertExists('boundary.txt');
    }

    public function test_cleanup_preserves_audit_rows_and_soft_deleted_models(): void
    {
        $setting = SystemSetting::query()->firstOrFail();
        $setting->audit('retention.canary', null, ['kept' => true]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->delete();

        Storage::disk('tmp')->put('orphan.txt', 'orphan');
        $this->age('orphan.txt', 25);

        (new CleanTemporaryStorageJob())->handle();

        Storage::disk('tmp')->assertMissing('orphan.txt');
        $this->assertSame(1, DB::table('activity_log')->where('event', 'retention.canary')->count());
        $this->assertNotNull(User::withTrashed()->find($user->id)?->deleted_at);
    }

    public function test_a_cleanup_writes_an_audit_row_with_count_and_bytes(): void
    {
        Storage::disk('tmp')->put('orphan.bin', str_repeat('a', 100));
        $this->age('orphan.bin', 25);

        (new CleanTemporaryStorageJob())->handle();

        $row = DB::table('activity_log')->where('event', 'temp_storage.cleaned')->first();

        $this->assertNotNull($row, 'a run that deleted files must leave its audit row');
        $this->assertNull($row->causer_id, 'a cleanup is a system action, not a user action');

        $properties = json_decode((string) $row->properties, true);

        $this->assertSame(1, $properties['deleted_count']);
        $this->assertSame(100, $properties['bytes_freed']);
        $this->assertSame(24, $properties['retention_hours']);
        $this->assertSame('system', $properties['source']);
        $this->assertSame('SYSTEM', $properties['causer']);
    }

    public function test_a_second_run_finds_nothing_and_writes_no_row(): void
    {
        Storage::disk('tmp')->put('orphan.txt', 'orphan');
        $this->age('orphan.txt', 25);

        (new CleanTemporaryStorageJob())->handle();
        (new CleanTemporaryStorageJob())->handle();

        $this->assertSame(1, DB::table('activity_log')->where('event', 'temp_storage.cleaned')->count());
    }

    public function test_the_retention_setting_is_bounded_and_persisted(): void
    {
        $admin = $this->admin();

        foreach ([0, 8761] as $outOfRange) {
            $this->actingAs($admin)
                ->from('/settings')
                ->post(route('settings.update'), ['temp_file_retention_hours' => $outOfRange])
                ->assertSessionHasErrors('temp_file_retention_hours');
        }

        $this->actingAs($admin)
            ->post(route('settings.update'), ['temp_file_retention_hours' => 48])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        SystemSetting::bustCache();

        $this->assertSame(48, SystemSetting::getInt('temp_file_retention_hours', 0));
    }

    public function test_the_job_rides_the_sweeps_queue(): void
    {
        $this->assertSame(QueueName::SWEEPS, (new CleanTemporaryStorageJob())->queue);
    }
}
