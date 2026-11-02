<?php

namespace Tests\Feature\Settings;

use App\Http\Resources\Api\V1\HealthCheck\HealthCheckResource;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\RoleLookup;
use App\Models\SystemSetting;
use App\Models\Timezone;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Backup\SystemBackupSchedule;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Connection;
use Mockery;
use Tests\TestCase;

/**
 * Phase 14 follow-up: one stored timezone governs the whole process.
 *
 * The setting is not a display flag — `bindTimezone()` calls PHP's default
 * timezone, which is what `now()`, Eloquent date parsing and every
 * `toIso8601String()` derive from.
 */
class GlobalTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
        $this->withoutMiddleware(VerifyCsrfToken::class);

        Timezone::create([
            'name' => 'Asia/Jakarta',
            'label' => 'Jakarta (GMT+7)',
            'utc_offset' => '+0700',
            'is_active' => true,
        ]);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find('admin'));

        return $user;
    }

    private function apply(string $timezone): void
    {
        SystemSetting::set('app_timezone', $timezone);
        AppServiceProvider::bindTimezone();
    }

    /**
     * The session zone follows the stored setting, not an env value: the zone
     * an operator reads the data in is the one the database should answer in.
     * `config/database.php` carries no `timezone` key, so no env can override
     * it and `applySessionTimezone()` is the only writer.
     */
    public function test_the_session_zone_follows_the_applied_timezone(): void
    {
        $this->assertArrayNotHasKey('timezone', config('database.connections.mysql'));

        $this->apply('Asia/Jakarta');

        $this->assertSame('+07:00', AppServiceProvider::sessionTimezoneOffset());
    }

    /**
     * `SET time_zone` is MySQL syntax. Every other driver — sqlite in the
     * suite — must be left alone, and must not even be connected to.
     */
    public function test_a_non_mysql_connection_is_left_alone(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->once()->andReturn('sqlite');
        $connection->shouldNotReceive('getPdo');

        AppServiceProvider::applySessionTimezone($connection);
    }

    public function test_saving_the_timezone_applies_it_to_the_process(): void
    {
        $this->actingAs($this->admin())
            ->post(route('settings.update'), ['app_timezone' => 'Asia/Jakarta'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('Asia/Jakarta', SystemSetting::getString('app_timezone'));
        $this->assertSame('Asia/Jakarta', config('app.timezone'));
        $this->assertSame('Asia/Jakarta', now()->getTimezone()->getName());
    }

    public function test_an_empty_timezone_falls_back_to_the_shipped_default(): void
    {
        $this->apply('Asia/Jakarta');
        $this->assertSame('Asia/Jakarta', now()->getTimezone()->getName());

        $this->actingAs($this->admin())
            ->post(route('settings.update'), ['app_timezone' => ''])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('', SystemSetting::getString('app_timezone'));
        $this->assertSame('UTC', now()->getTimezone()->getName());
    }

    public function test_api_timestamps_carry_the_configured_offset(): void
    {
        $this->apply('Asia/Jakarta');

        $resource = new HealthCheckResource(['app' => 'ok']);

        $this->assertStringEndsWith('+07:00', $resource->toArray(request())['timestamp']);
        $this->assertStringEndsWith('+07:00', now()->toIso8601String());
    }

    public function test_an_inactive_timezone_is_rejected(): void
    {
        Timezone::create([
            'name' => 'Asia/Makassar',
            'label' => 'Makassar (GMT+8)',
            'utc_offset' => '+0800',
            'is_active' => false,
        ]);

        $this->actingAs($this->admin())
            ->post(route('settings.update'), ['app_timezone' => 'Asia/Makassar'])
            ->assertRedirect()
            ->assertSessionHasErrors('app_timezone');

        $this->assertNotSame('Asia/Makassar', SystemSetting::getString('app_timezone'));
        $this->assertSame('UTC', now()->getTimezone()->getName());
    }

    public function test_the_backup_schedule_follows_the_global_timezone(): void
    {
        SystemSetting::set('backup_enabled', 'true');
        SystemSetting::set('backup_schedule', '02:00');
        $this->apply('Asia/Jakarta');

        $schedule = app(SystemBackupSchedule::class);

        $this->assertTrue($schedule->isDue(now()->setTime(2, 0)));
        $this->assertFalse($schedule->isDue(now()->setTime(2, 1)));
    }
}
