<?php

namespace Tests\Feature\Queue;

use App\Jobs\Concerns\ReadsCorrelationId;
use App\Jobs\InactivityLockSweep;
use App\Jobs\PasswordExpirySweep;
use App\Support\QueueName;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 11 Group E (P11-E1..E3) as a test, so the queue conventions stay
 * conventions instead of a per-job habit.
 *
 * The failure this file exists to prevent: a new job class that silently
 * rides `default`, declares nothing about retries, and has no `failed()` —
 * every one of those is invisible in review and only shows up as a stuck
 * queue, a job that dies on the first hiccup, or a failure nobody was told
 * about. The conventions are asserted over every class in `app/Jobs` by
 * reflection, so a fourth job is covered the day it is written.
 *
 * The payload test pins the OTHER half of P11-E3: the correlation id is
 * captured at push time (still inside the request) because the worker that
 * runs the job is a different process where `app('request_id')` was never
 * bound — see `AppServiceProvider` and `ReadsCorrelationId`.
 */
class JobConventionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every job class in `app/Jobs` — Concerns are traits and are skipped by
     * the glob's depth.
     *
     * @return array<int, class-string>
     */
    private function jobClasses(): array
    {
        $classes = [];

        foreach (glob(app_path('Jobs/*.php')) as $file) {
            $classes[] = 'App\Jobs\\'.basename($file, '.php');
        }

        $this->assertNotEmpty($classes, 'no jobs found to check conventions against');

        return $classes;
    }

    public function test_every_job_is_queued_on_a_declared_application_queue(): void
    {
        $declared = (new ReflectionClass(QueueName::class))->getConstants();

        foreach ($this->jobClasses() as $class) {
            $this->assertTrue(
                is_subclass_of($class, ShouldQueue::class),
                "{$class} does not implement ShouldQueue"
            );

            $queue = $this->instanceOf($class)->queue;

            $this->assertNotNull(
                $queue,
                "{$class} sets no queue — it silently rides `default` and the per-queue view counts it there"
            );

            $this->assertContains(
                $queue,
                $declared,
                "{$class} names a queue QueueName does not declare"
            );
        }
    }

    /**
     * An instance fed with dummy constructor arguments.
     *
     * The queue name is set ON THE INSTANCE (`onQueue()` in the constructor),
     * because redeclaring `Queueable`'s `$queue` property with a default is a
     * class-load fatal that `php -l` does not catch — so reflection has to run
     * the constructor to see the value. A future job with a constructor this
     * helper cannot feed fails loudly here and teaches it the type.
     */
    private function instanceOf(string $class): object
    {
        $reflection = new ReflectionClass($class);

        $arguments = array_map(
            fn (ReflectionParameter $parameter): mixed => match ($parameter->getType()?->getName()) {
                'int' => 1,
                'array' => [],
                'string' => 'x',
                'bool' => true,
                default => throw new RuntimeException(
                    "{$class} has a constructor this test cannot feed — teach instanceOf() the type of \${$parameter->getName()}"
                ),
            },
            array_filter(
                $reflection->getConstructor()?->getParameters() ?? [],
                fn (ReflectionParameter $parameter): bool => ! $parameter->isDefaultValueAvailable(),
            ),
        );

        return $reflection->newInstanceArgs($arguments);
    }

    public function test_every_job_declares_its_retry_chain(): void
    {
        foreach ($this->jobClasses() as $class) {
            foreach (['tries', 'timeout', 'maxExceptions'] as $property) {
                $value = get_class_vars($class)[$property] ?? null;

                $this->assertIsInt($value, "{$class} does not declare \${$property}");
                $this->assertGreaterThan(0, $value, "{$class} declares a non-positive \${$property}");
            }

            $this->assertTrue(method_exists($class, 'backoff'), "{$class} has no backoff()");

            // Without the constructor: GenerateAuditExport's requires arguments,
            // and backoff() answers from constants alone.
            $backoff = (new ReflectionMethod($class, 'backoff'))
                ->invoke((new ReflectionClass($class))->newInstanceWithoutConstructor());

            $this->assertNotEmpty($backoff, "{$class} backoff() is empty");

            foreach ($backoff as $seconds) {
                $this->assertIsInt($seconds, "{$class} backoff() returns a non-integer");
            }
        }
    }

    public function test_every_job_has_a_failed_hook(): void
    {
        foreach ($this->jobClasses() as $class) {
            $this->assertTrue(method_exists($class, 'failed'), "{$class} has no failed() hook");
        }
    }

    public function test_the_payload_key_is_present_even_without_a_request(): void
    {
        config(['queue.default' => 'database']);

        // A scheduler-dispatched sweep has no request behind it: the key must
        // be present and null, not absent, so a log consumer can rely on the
        // field existing on every job's payload.
        InactivityLockSweep::dispatch();

        $payload = json_decode(DB::table('jobs')->value('payload'), true);

        $this->assertArrayHasKey('request_id', $payload);
        $this->assertNull($payload['request_id']);
    }

    public function test_the_payload_carries_the_dispatch_time_request_id(): void
    {
        config(['queue.default' => 'database']);
        app()->instance('request_id', 'req-convention-test');

        PasswordExpirySweep::dispatch();

        $payload = json_decode(DB::table('jobs')->value('payload'), true);

        $this->assertSame('req-convention-test', $payload['request_id']);
    }

    /**
     * The read side: payload string comes back, garbage does not, and a job
     * invoked without a queue job behind it (`failed()` called directly, old
     * payloads) answers null rather than erroring.
     */
    public function test_the_correlation_read_is_null_safe(): void
    {
        $reader = new class () {
            use InteractsWithQueue;
            use ReadsCorrelationId;

            public function id(): ?string
            {
                return $this->requestId();
            }
        };

        $this->assertNull($reader->id(), 'no queue job behind the read must answer null');

        $reader->job = new class ('{"request_id":"req-from-payload"}') {
            public function __construct(private readonly string $payload)
            {
            }

            public function payload(): array
            {
                return json_decode($this->payload, true);
            }
        };

        $this->assertSame('req-from-payload', $reader->id());

        $reader->job = new class ('{"request_id":42}') {
            public function __construct(private readonly string $payload)
            {
            }

            public function payload(): array
            {
                return json_decode($this->payload, true);
            }
        };

        $this->assertNull($reader->id(), 'a non-string payload value must not leak into the log');
    }
}
