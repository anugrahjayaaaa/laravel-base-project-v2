<?php

namespace Tests\Feature\Vantage;

use App\Http\Middleware\VerifyCsrfToken;
use App\Jobs\PasswordExpirySweep;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\QueueName;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Storvia\Vantage\Models\VantageJob;
use Tests\TestCase;

/**
 * Phase 11 §13, ADR-022: `POST /vantage/jobs/{id}/retry` re-queues a job.
 *
 * The button was a dead end on this install — Vantage's retry re-dispatches the
 * job from `vantage_jobs.payload`, and `store_payload` is forced OFF, so every
 * press answered "Unable to restore job. Payload might be missing or
 * corrupted." `App\Support\Vantage\FailedJobPayloadRestorer` (bound in
 * `AppServiceProvider::register()`) answers from the failed-job store the
 * framework already keeps for `queue:retry` instead of a second copy of the
 * job's arguments.
 *
 * Three cases, one per branch the restorer can take:
 *
 * 1. no Vantage payload + a failed-job record exists → the job is re-queued
 *    onto its own queue
 * 2. no Vantage payload + the record is gone (`queue:flush`) → refused, and
 *    nothing is dispatched
 * 3. a Vantage payload exists → the package's own path still wins, which is
 *    what a deployment setting `VANTAGE_STORE_PAYLOAD=true` gets
 */
class VantageRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        // CSRF is enforced in tests (VerifyCsrfToken::runningUnitTests() is
        // hardcoded true here); the subject is the retry path, so skip CSRF
        // rather than carry a token — same approach as VantagePermissionGateTest.
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_retry_restores_the_job_from_the_framework_failed_job_record(): void
    {
        $this->retryHolder();

        $run = $this->failedAttemptRow($this->failAJob());

        Queue::fake();

        $this->post(route('vantage.jobs.retry', $run))
            ->assertRedirect()
            ->assertSessionHas('success');

        Queue::assertPushedOn(QueueName::SWEEPS, PasswordExpirySweep::class);
    }

    public function test_retry_refuses_when_the_failed_job_record_is_gone(): void
    {
        $this->retryHolder();

        // A uuid nothing logged a failure for: the Vantage row outlived the
        // framework's copy (`queue:flush`, `queue:prune-failed`).
        $run = $this->failedAttemptRow((string) Str::uuid());

        Queue::fake();

        $this->post(route('vantage.jobs.retry', $run))
            ->assertRedirect()
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    public function test_the_vantage_payload_still_wins_when_it_exists(): void
    {
        $this->retryHolder();

        // No failed-job record at all: only the package's own payload path can
        // dispatch this one.
        $run = $this->failedAttemptRow((string) Str::uuid(), [
            'payload' => [
                'raw_payload' => ['data' => ['command' => serialize(new PasswordExpirySweep())]],
            ],
        ]);

        Queue::fake();

        $this->post(route('vantage.jobs.retry', $run))
            ->assertRedirect()
            ->assertSessionHas('success');

        Queue::assertPushedOn(QueueName::SWEEPS, PasswordExpirySweep::class);
    }

    /**
     * Write the failure the way the worker writes it.
     *
     * The payload is built by the queue itself (pushed to the `database`
     * connection and read back) rather than hand-written JSON, so the test
     * consumes the same string a real `queue:work` would hand to
     * `WorkCommand::logFailedJob()`.
     *
     * @return string The queue payload's uuid — the key both stores share.
     */
    private function failAJob(): string
    {
        Queue::connection('database')->push(new PasswordExpirySweep());

        $payload = (string) DB::table('jobs')->orderByDesc('id')->value('payload');

        DB::table('jobs')->delete();

        app(FailedJobProviderInterface::class)->log(
            'database',
            QueueName::SWEEPS,
            $payload,
            new RuntimeException('simulated failure'),
        );

        return (string) json_decode($payload, true)['uuid'];
    }

    /**
     * The attempt row Vantage records — `payload` left unset, exactly as
     * `store_payload => false` leaves it in production.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function failedAttemptRow(string $uuid, array $overrides = []): VantageJob
    {
        return VantageJob::create(array_merge([
            'uuid' => $uuid,
            'job_class' => PasswordExpirySweep::class,
            'queue' => QueueName::SWEEPS,
            'connection' => 'database',
            'attempt' => 3,
            'status' => 'failed',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'exception_class' => RuntimeException::class,
            'exception_message' => 'simulated failure',
        ], $overrides));
    }

    private function retryHolder(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find('admin'));

        $this->assertTrue($user->can('queues.retry'), 'precondition: admin holds queues.retry');

        $this->actingAs($user, 'web');

        return $user;
    }
}
