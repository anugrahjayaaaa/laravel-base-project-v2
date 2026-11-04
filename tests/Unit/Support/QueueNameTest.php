<?php

namespace Tests\Unit\Support;

use App\Support\QueueName;
use ReflectionClass;
use Tests\TestCase;

/**
 * `App\Support\QueueName` is the one list the dashboard label, the worker's
 * `--queue` flag and a job's `$queue` are meant to be three readings of.
 *
 * The failure this file pins is silent: a queue name that exists in one of the
 * three places and not the others either never gets consumed (a worker that
 * does not listen for it) or never gets named (the job rides `default` and the
 * per-queue view lies). Neither produces an error; both produce a stuck job.
 */
class QueueNameTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function queues(): array
    {
        $constants = (new ReflectionClass(QueueName::class))->getConstants();

        $this->assertNotEmpty($constants, 'QueueName declares no queue at all');

        return $constants;
    }

    /**
     * `default` is the connection's fallback, not an application queue — and
     * naming it here would make "this job has a queue" true for a job that
     * never set one, which is the whole thing the class prevents.
     */
    public function test_default_is_not_an_application_queue(): void
    {
        $this->assertNotContains(
            'default',
            $this->queues(),
            'QueueName declares `default`; a job naming it is indistinguishable from a job naming nothing'
        );
    }

    public function test_every_declared_queue_is_a_unique_lowercase_slug(): void
    {
        $queues = $this->queues();

        $this->assertSame(
            array_values($queues),
            array_values(array_unique($queues)),
            'two constants hold the same queue name, so one of them owns no queue'
        );

        foreach ($queues as $constant => $queue) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_-]*$/',
                $queue,
                "QueueName::{$constant} is not a queue-name slug, so it cannot match the worker's --queue token"
            );
        }
    }

    /**
     * The other half of the list: a worker only consumes what it listens for.
     * `backups` rides its own connection and its own worker invocation, so both
     * defaults are read rather than assuming the first one covers everything.
     */
    public function test_every_declared_queue_is_consumed_by_the_worker_script(): void
    {
        $script = (string) file_get_contents(base_path('bin/run-workers.sh'));

        preg_match('/QUEUE_NAME="\$\{QUEUE_NAME:-([^}]*)\}"/', $script, $main);
        preg_match('/BACKUP_QUEUE="\$\{BACKUP_QUEUE:-([^}]*)\}"/', $script, $backup);

        $this->assertNotEmpty($main, 'bin/run-workers.sh no longer defaults QUEUE_NAME — this test needs teaching');
        $this->assertNotEmpty($backup, 'bin/run-workers.sh no longer defaults BACKUP_QUEUE — this test needs teaching');

        $listened = array_merge(explode(',', $main[1]), explode(',', $backup[1]));

        foreach ($this->queues() as $constant => $queue) {
            $this->assertContains(
                $queue,
                $listened,
                "QueueName::{$constant} = `{$queue}` has no worker listening for it — its jobs would wait forever"
            );
        }
    }
}
