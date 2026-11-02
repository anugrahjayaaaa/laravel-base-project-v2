<?php

namespace App\Jobs;

use App\Actions\V1\System\SystemBackupRunAction;
use App\Jobs\Concerns\ReadsCorrelationId;
use App\Models\User;
use App\Support\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Run one system backup off the request path.
 *
 * A backup is a `mysqldump` plus a directory archive: minutes of IO and a real
 * risk of exceeding a web request's timeout. The scheduler and the manual
 * trigger both dispatch here, and the job owns the pre-check, the audit rows
 * and the outcome notification through `SystemBackupRunAction`.
 *
 * The causer id is the operator who pressed the button; the scheduled run has
 * none. It is stored as an id rather than a serialized user so a deleted
 * account does not resurrect as a stale model in the worker.
 */
class ExecuteSystemBackupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use ReadsCorrelationId;
    use SerializesModels;

    public function __construct(private readonly ?int $causerId = null)
    {
        // A backup runs for minutes while every other job finishes in seconds,
        // so it rides its own connection — the one whose `retry_after` is
        // longer than `$timeout` below. On the shared connection a second
        // worker would re-reserve the row mid-run and, because `$tries` is 1,
        // fail a backup that is still writing. `bin/run-workers.sh backups`
        // consumes it; see config/queue.php.
        $this->onConnection('backups');
        $this->onQueue(QueueName::BACKUPS);
    }

    /**
     * One attempt. A partially written backup is retried by an operator, not
     * by the queue: a second automatic run doubles the IO without knowing
     * whether the first left a complete artifact.
     *
     * @var int
     */
    public $tries = 1;

    /** @var int */
    public $timeout = 1800;

    /** @var int */
    public $maxExceptions = 1;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [300];
    }

    public function handle(SystemBackupRunAction $action): void
    {
        $causer = $this->causerId !== null ? User::find($this->causerId) : null;

        $action->run($causer);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('System backup failed', [
            'attempts' => $this->tries,
            'request_id' => $this->requestId(),
            'exception' => $exception?->getMessage(),
        ]);
    }
}
