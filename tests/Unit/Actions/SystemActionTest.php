<?php

namespace Tests\Unit\Actions;

use App\Actions\V1\System\SystemBackupRunAction;
use App\Actions\V1\System\SystemSettingsUpdateAction;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Backup\SystemBackupRunner;
use App\Services\Backup\SystemBackupSizeEstimator;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The two System actions: the backup pre-flight (refuse rather than fill the
 * disk) and the settings write path.
 */
class SystemActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    // === backup ===

    public function test_a_backup_that_would_not_fit_is_refused_before_the_runner_is_touched(): void
    {
        $this->sizeEstimate(total: 950, available: 1000);
        $this->mock(SystemBackupRunner::class)->shouldReceive('run')->never();

        app(SystemBackupRunAction::class)->run($this->admin());

        // 950 + the 10% reserve does not fit in 1000, and a refused backup is a
        // recorded event, not a silent no-op.
        $this->assertSame('backup.aborted_storage_full', $this->audit('backup.aborted_storage_full')->event);
        $this->assertSame(0, Activity::query()->where('event', 'backup.triggered')->count());
    }

    public function test_an_unmeasurable_destination_does_not_disable_backups(): void
    {
        // Unknown free space passes: refusing every backup because the disk
        // cannot be measured turns a monitoring gap into a dead feature.
        $this->sizeEstimate(total: 10_000, available: null);

        $runner = $this->mock(SystemBackupRunner::class);
        $runner->shouldReceive('run')->once();

        app(SystemBackupRunAction::class)->run($this->admin());

        $this->assertSame('backup.triggered', $this->audit('backup.triggered')->event);
    }

    public function test_the_scheduled_run_is_marked_as_the_system_acting(): void
    {
        $this->sizeEstimate(total: 10, available: 100);
        $this->mock(SystemBackupRunner::class)->shouldReceive('run')->once();

        app(SystemBackupRunAction::class)->run(null);

        // A null causer alone renders as "Anonymous", which is the wrong story
        // for a scheduler that genuinely acted.
        $row = $this->audit('backup.triggered');
        $this->assertSame('SYSTEM', $row->properties['causer']);
        $this->assertSame('system', $row->properties['source']);
    }

    // === settings ===

    public function test_a_settings_save_audits_the_keys_it_wrote(): void
    {
        app(SystemSettingsUpdateAction::class)->run(
            ['login_max_attempts' => 7],
            partial: true,
            causer: $this->admin()
        );

        // 7 differs from the seeded 5, so it survives the diff that drops
        // unchanged keys and actually reaches the table.
        $this->assertSame('7', SystemSetting::getString('login_max_attempts'));

        // The audit payload is the normalized settings payload itself, not a
        // before/after shape — the activity row is the record of what was sent.
        $row = $this->audit('system_setting.updated');
        $this->assertSame(7, (int) $row->properties['login_max_attempts']);
    }

    // === transaction boundary ===

    public function test_a_settings_save_is_rolled_back_when_the_audit_write_fails(): void
    {
        Event::listen('eloquent.creating: '.Activity::class, fn () => throw new RuntimeException('audit write failed'));

        try {
            app(SystemSettingsUpdateAction::class)->run(['login_max_attempts' => 7], partial: true, causer: $this->admin());
            $this->fail('The action completed with a broken audit write.');
        } catch (RuntimeException) {
            // expected
        }

        // Read the table, not SystemSetting::get*: the static request cache holds
        // the values the rolled-back transaction wrote.
        $this->assertSame('5', DB::table('system_settings')->where('key', 'login_max_attempts')->value('value'));
    }

    // === helpers ===

    private function sizeEstimate(int $total, ?int $available): void
    {
        $estimator = $this->mock(SystemBackupSizeEstimator::class);
        $estimator->shouldReceive('estimate')->andReturn([
            'total' => $total,
            'components' => [],
        ]);
        $estimator->shouldReceive('availableBytes')->with('backups')->andReturn($available);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole(SystemRole::ADMIN);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin;
    }

    private function audit(string $event): Activity
    {
        return Activity::query()->where('event', $event)->latest('id')->firstOrFail();
    }
}
