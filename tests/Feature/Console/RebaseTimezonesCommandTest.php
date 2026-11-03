<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The migration path for history written under the old zone.
 *
 * `app_timezone` changes how a stored value is interpreted, so a zone switch
 * without this command makes every earlier row render shifted.
 */
class RebaseTimezonesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function userAt(string $timestamp): User
    {
        $user = User::factory()->create();

        DB::table('users')->where('id', $user->id)->update([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $user;
    }

    private function storedCreatedAt(User $user): string
    {
        return (string) DB::table('users')->where('id', $user->id)->value('created_at');
    }

    public function test_it_shifts_timestamp_columns_from_one_zone_to_another(): void
    {
        $user = $this->userAt('2026-01-01 10:00:00');

        $this->artisan('timezone:rebase', [
            '--from' => 'UTC',
            '--to' => 'Asia/Jakarta',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame('2026-01-01 17:00:00', $this->storedCreatedAt($user));
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $user = $this->userAt('2026-01-01 10:00:00');

        $this->artisan('timezone:rebase', [
            '--from' => 'UTC',
            '--to' => 'Asia/Jakarta',
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame('2026-01-01 10:00:00', $this->storedCreatedAt($user));
    }

    public function test_same_source_and_target_is_a_no_op(): void
    {
        $user = $this->userAt('2026-01-01 10:00:00');

        $this->artisan('timezone:rebase', [
            '--from' => 'UTC',
            '--to' => 'UTC',
            '--force' => true,
        ])->expectsOutput('Source and target timezone are the same; nothing to do.')
            ->assertSuccessful();

        $this->assertSame('2026-01-01 10:00:00', $this->storedCreatedAt($user));
    }

    public function test_an_invalid_timezone_is_refused(): void
    {
        $this->artisan('timezone:rebase', [
            '--from' => 'UTC',
            '--to' => 'Mars/Olympus',
            '--force' => true,
        ])->assertFailed();
    }

    /**
     * Production replaces the confirmation prompt with an explicit flag: the
     * command is a bulk rewrite of every timestamp column, and there the intent
     * should never be satisfiable by an accidental empty stdin.
     */
    public function test_production_refuses_the_rebase_without_force(): void
    {
        $user = $this->userAt('2026-01-01 10:00:00');
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->artisan('timezone:rebase', [
            '--from' => 'UTC',
            '--to' => 'Asia/Jakarta',
        ])->assertFailed();

        $this->assertSame('2026-01-01 10:00:00', $this->storedCreatedAt($user));
    }

    public function test_production_runs_with_force(): void
    {
        $user = $this->userAt('2026-01-01 10:00:00');
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->artisan('timezone:rebase', [
            '--from' => 'UTC',
            '--to' => 'Asia/Jakarta',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame('2026-01-01 17:00:00', $this->storedCreatedAt($user));
    }
}
