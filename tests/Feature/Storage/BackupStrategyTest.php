<?php

namespace Tests\Feature\Storage;

use App\Actions\V1\System\SystemBackupRunAction;
use App\Http\Middleware\VerifyCsrfToken;
use App\Jobs\ExecuteSystemBackupJob;
use App\Models\RoleLookup;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\ConfigurationChangedNotification;
use App\Services\Backup\SystemBackupRunner;
use App\Services\Backup\SystemBackupSchedule;
use App\Services\Backup\SystemBackupSizeEstimator;
use App\Support\QueueName;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Laravel\Pennant\Feature;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 14 Group B gate: the schedule is data, execution is queued, the
 * destination is checked before the command runs, and both outcomes are
 * audited and announced to the people who may run a backup.
 */
class BackupStrategyTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE_FILE = 'testing-backup-source.txt';

    private const DESTINATION_FILE = 'testing-backup-destination.bin';

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

    protected function tearDown(): void
    {
        File::delete(storage_path('app/'.self::SOURCE_FILE));
        File::delete(storage_path('app/backups/'.self::DESTINATION_FILE));
        File::deleteDirectory(storage_path('app/testing-backup-temp'));

        parent::tearDown();
    }

    private function admin(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find('admin'));

        return $user;
    }

    private function set(string $key, string $value): void
    {
        SystemSetting::set($key, $value);
        SystemSetting::bustCache();
    }

    public function test_disabled_backup_never_dispatches(): void
    {
        $this->set('backup_enabled', 'false');
        $this->set('backup_schedule', '02:00');

        Bus::fake();

        $this->assertFalse(app(SystemBackupSchedule::class)->dispatchIfDue(now()->setTime(2, 0)));
        Bus::assertNotDispatched(ExecuteSystemBackupJob::class);
    }

    public function test_the_stored_schedule_decides_the_next_run(): void
    {
        $schedule = app(SystemBackupSchedule::class);

        $this->set('backup_enabled', 'true');
        $this->set('backup_schedule', '02:00');

        $this->assertTrue($schedule->isDue(now()->setTime(2, 0)));
        $this->assertFalse($schedule->isDue(now()->setTime(2, 1)));

        // Changing the setting moves the run without a deploy or restart.
        $this->set('backup_schedule', '23:45');

        $this->assertFalse($schedule->isDue(now()->setTime(2, 0)));
        $this->assertTrue($schedule->isDue(now()->setTime(23, 45)));
    }

    public function test_due_schedule_dispatches_the_job_on_the_backups_queue(): void
    {
        $this->set('backup_enabled', 'true');
        $this->set('backup_schedule', '02:00');

        Bus::fake();

        $this->assertTrue(app(SystemBackupSchedule::class)->dispatchIfDue(now()->setTime(2, 0)));

        Bus::assertDispatched(
            ExecuteSystemBackupJob::class,
            fn (ExecuteSystemBackupJob $job): bool => $job->queue === QueueName::BACKUPS
        );
    }

    public function test_manual_trigger_dispatches_after_commit(): void
    {
        Bus::fake();

        $this->actingAs($this->admin())
            ->post(route('storage.backup.store'))
            ->assertRedirect();

        Bus::assertDispatched(
            ExecuteSystemBackupJob::class,
            fn (ExecuteSystemBackupJob $job): bool => $job->afterCommit === true
        );
    }

    public function test_manual_trigger_requires_the_permission(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->post(route('storage.backup.store'))
            ->assertForbidden();
    }

    public function test_manual_trigger_is_closed_when_the_feature_flag_is_off(): void
    {
        Feature::deactivate('storage_management');
        Feature::flushCache();

        $this->actingAs($this->admin())
            ->post(route('storage.backup.store'))
            ->assertForbidden();
    }

    public function test_the_job_declares_the_queue_standard(): void
    {
        $job = new ExecuteSystemBackupJob();

        $this->assertSame(QueueName::BACKUPS, $job->queue);
        $this->assertSame(1, $job->tries);
        $this->assertSame(1800, $job->timeout);
        $this->assertSame(1, $job->maxExceptions);
        $this->assertNotEmpty($job->backoff());
    }

    /**
     * The invariant that makes `$tries = 1` safe.
     *
     * Both queue drivers re-select a job whose reservation is older than
     * `retry_after`, and every pop increments `attempts` — so a window shorter
     * than the job's own timeout would let a second worker mark a backup that
     * is still writing as failed. The retry window is read from the consuming
     * connection, which is why the job names its own.
     */
    public function test_the_backup_connection_outlives_the_job_timeout(): void
    {
        $job = new ExecuteSystemBackupJob();
        $connection = config('queue.connections.'.$job->connection);

        $this->assertSame('backups', $job->connection);
        $this->assertSame(QueueName::BACKUPS, $connection['queue']);
        $this->assertGreaterThan($job->timeout, $connection['retry_after']);
    }

    /**
     * The single `backups` worker serializes by accident, not by contract. The
     * overlap lock is the contract, and its expiry must outlive `$timeout` or
     * a lock freed mid-run admits a second backup over a half-written archive.
     */
    public function test_the_job_locks_out_an_overlapping_run(): void
    {
        $job = new ExecuteSystemBackupJob();

        [$middleware] = $job->middleware();

        $this->assertInstanceOf(WithoutOverlapping::class, $middleware);
        $this->assertSame('system-backup', $middleware->key);
        $this->assertGreaterThan($job->timeout, $middleware->expiresAfter);
    }

    public function test_the_manual_trigger_is_rate_limited(): void
    {
        Bus::fake();

        $admin = $this->admin();

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($admin)->post(route('storage.backup.store'))->assertRedirect();
        }

        $this->actingAs($admin)
            ->post(route('storage.backup.store'))
            ->assertRedirect()
            ->assertSessionHas('error');

        Bus::assertDispatchedTimes(ExecuteSystemBackupJob::class, 3);
    }

    public function test_a_destination_that_cannot_hold_the_backup_aborts_without_running_it(): void
    {
        Notification::fake();
        $admin = $this->admin();

        $this->mock(SystemBackupSizeEstimator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('estimate')->once()->andReturn([
                'files' => 900,
                'database' => 100,
                'total' => 1000,
            ]);
            $mock->shouldReceive('availableBytes')->once()->with('backups')->andReturn(100);
        });

        $this->mock(SystemBackupRunner::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        app(SystemBackupRunAction::class)->run($admin);

        $this->assertDatabaseHas('activity_log', ['event' => 'backup.aborted_storage_full']);
        $this->assertDatabaseMissing('activity_log', ['event' => 'backup.triggered']);

        Notification::assertSentTo($admin, ConfigurationChangedNotification::class);
    }

    public function test_a_scheduled_backup_with_no_operator_is_audited_as_system(): void
    {
        Notification::fake();

        $this->mock(SystemBackupSizeEstimator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('estimate')->once()->andReturn([
                'files' => 900,
                'database' => 100,
                'total' => 1000,
            ]);
            $mock->shouldReceive('availableBytes')->once()->with('backups')->andReturn(100);
        });

        $this->mock(SystemBackupRunner::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        app(SystemBackupRunAction::class)->run();

        $row = DB::table('activity_log')->where('event', 'backup.aborted_storage_full')->first();

        $this->assertNotNull($row);
        $this->assertNull($row->causer_id);
        $this->assertSame('SYSTEM', json_decode((string) $row->properties, true)['causer'] ?? null);
    }

    public function test_a_successful_backup_is_audited_and_announced(): void
    {
        Notification::fake();
        $admin = $this->admin();

        $this->mock(SystemBackupSizeEstimator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('estimate')->once()->andReturn([
                'files' => 900,
                'database' => 100,
                'total' => 1000,
            ]);
            $mock->shouldReceive('availableBytes')->once()->with('backups')->andReturn(100000);
        });

        $this->mock(SystemBackupRunner::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->once();
        });

        app(SystemBackupRunAction::class)->run($admin);

        $this->assertDatabaseHas('activity_log', ['event' => 'backup.triggered']);
        Notification::assertSentTo($admin, ConfigurationChangedNotification::class);
    }

    public function test_a_failed_backup_is_announced_and_rethrown(): void
    {
        Notification::fake();
        $admin = $this->admin();

        $this->mock(SystemBackupSizeEstimator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('estimate')->once()->andReturn([
                'files' => 0,
                'database' => 0,
                'total' => 0,
            ]);
            $mock->shouldReceive('availableBytes')->once()->with('backups')->andReturn(null);
        });

        $this->mock(SystemBackupRunner::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->once()->andThrow(new RuntimeException('dump failed'));
        });

        try {
            app(SystemBackupRunAction::class)->run($admin);
            $this->fail('the action must rethrow a failed backup.');
        } catch (RuntimeException $e) {
            $this->assertSame('dump failed', $e->getMessage());
        }

        $this->assertDatabaseHas('activity_log', ['event' => 'backup.triggered']);
        Notification::assertSentTo($admin, ConfigurationChangedNotification::class);
    }

    public function test_production_refuses_an_unencrypted_backup(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['backup.backup.password' => null]);

        Artisan::shouldReceive('call')->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('BACKUP_ARCHIVE_PASSWORD');

        app(SystemBackupRunner::class)->run();
    }

    public function test_production_runs_when_an_archive_password_is_configured(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['backup.backup.password' => 'secret']);

        Artisan::shouldReceive('call')->twice()->andReturn(Command::SUCCESS);

        app(SystemBackupRunner::class)->run();
    }

    public function test_a_failed_backup_removes_the_plaintext_temporary_directory(): void
    {
        $temp = storage_path('app/testing-backup-temp');
        config([
            'backup.backup.password' => 'secret',
            'backup.backup.temporary_directory' => $temp,
        ]);

        File::ensureDirectoryExists($temp.'/db-dumps');
        File::put($temp.'/db-dumps/database.sql', 'plaintext');

        Artisan::shouldReceive('call')
            ->once()
            ->with('backup:run', ['--disable-notifications' => true])
            ->andThrow(new RuntimeException('dump failed'));

        try {
            app(SystemBackupRunner::class)->run();
            $this->fail('A failed backup must be rethrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('dump failed', $e->getMessage());
        }

        $this->assertDirectoryDoesNotExist($temp);
    }

    public function test_the_trigger_audit_row_is_discarded_with_its_transaction(): void
    {
        Notification::fake();

        $this->mock(SystemBackupSizeEstimator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('estimate')->once()->andReturn([
                'files' => 0,
                'database' => 0,
                'total' => 0,
            ]);
            $mock->shouldReceive('availableBytes')->once()->with('backups')->andReturn(null);
        });

        $this->mock(SystemBackupRunner::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->once();
        });

        DB::beginTransaction();
        app(SystemBackupRunAction::class)->run($this->admin());
        DB::rollBack();

        $this->assertDatabaseMissing('activity_log', ['event' => 'backup.triggered']);
    }

    public function test_retention_settings_rewrite_the_package_windows(): void
    {
        $this->set('backup_retention_daily', '10');
        $this->set('backup_retention_weekly', '5');
        $this->set('backup_retention_monthly', '18');

        app(SystemBackupRunner::class)->applyRetention();

        $this->assertSame(0, config('backup.cleanup.default_strategy.keep_all_backups_for_days'));
        $this->assertSame(10, config('backup.cleanup.default_strategy.keep_daily_backups_for_days'));
        $this->assertSame(5, config('backup.cleanup.default_strategy.keep_weekly_backups_for_weeks'));
        $this->assertSame(18, config('backup.cleanup.default_strategy.keep_monthly_backups_for_months'));
        $this->assertSame(0, config('backup.cleanup.default_strategy.keep_yearly_backups_for_years'));
    }

    public function test_the_estimate_excludes_the_backup_destination(): void
    {
        $estimator = app(SystemBackupSizeEstimator::class);
        $before = $estimator->filesBytes();

        File::ensureDirectoryExists(storage_path('app/backups'));
        File::put(storage_path('app/'.self::SOURCE_FILE), str_repeat('a', 100));
        File::put(storage_path('app/backups/'.self::DESTINATION_FILE), str_repeat('b', 5000));

        $this->assertSame($before + 100, $estimator->filesBytes());
    }

    public function test_backup_settings_are_persisted_and_validated(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('settings.update'), [
            'backup_enabled' => '1',
            'backup_schedule' => '03:15',
            'backup_retention_daily' => '10',
            'backup_retention_weekly' => '6',
            'backup_retention_monthly' => '24',
        ])->assertRedirect();

        SystemSetting::bustCache();

        $this->assertTrue(SystemSetting::getBool('backup_enabled'));
        $this->assertSame('03:15', SystemSetting::getString('backup_schedule'));
        $this->assertSame(10, SystemSetting::getInt('backup_retention_daily'));
        $this->assertSame(6, SystemSetting::getInt('backup_retention_weekly'));
        $this->assertSame(24, SystemSetting::getInt('backup_retention_monthly'));

        $this->actingAs($admin)->post(route('settings.update'), [
            'backup_schedule' => '25:99',
        ])->assertSessionHasErrors('backup_schedule');
    }
}
