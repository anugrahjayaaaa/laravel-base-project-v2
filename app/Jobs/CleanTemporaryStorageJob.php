<?php

namespace App\Jobs;

use App\Jobs\Concerns\ReadsCorrelationId;
use App\Models\SystemSetting;
use App\Support\QueueName;
use FilesystemIterator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Delete unattached files on the `tmp` disk past the retention window.
 *
 * Uploads land on `tmp` first and are promoted on save — anything still here
 * past the TTL is orphaned by definition. Scheduled daily, and dispatched by
 * the capacity guard on a warning-threshold crossing (hourly throttle). Never
 * touches `local` / `public` / `secure_documents`: an unattached file there
 * cannot be proven orphaned.
 */
class CleanTemporaryStorageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use ReadsCorrelationId;
    use SerializesModels;

    public function __construct()
    {
        $this->onQueue(QueueName::SWEEPS);
    }

    /** @var int */
    public $tries = 3;

    /** @var int */
    public $timeout = 150;

    /** @var int */
    public $maxExceptions = 3;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Temporary storage cleanup failed', [
            'attempts' => $this->tries,
            'request_id' => $this->requestId(),
            'exception' => $exception?->getMessage(),
        ]);
    }

    /**
     * Delete every `tmp` object strictly older than the retention window.
     *
     * A second run finds nothing and writes no audit row — the empty pass is
     * the idempotence proof Group C pins.
     */
    public function handle(): void
    {
        $hours = max(1, SystemSetting::getInt('temp_file_retention_hours', 24));
        $cutoff = now()->subHours($hours)->getTimestamp();

        $disk = Storage::disk('tmp');
        $deleted = 0;
        $bytes = 0;

        // `path('')` ends with a separator, which the relative-path slice below
        // would otherwise count twice.
        $root = rtrim($disk->path(''), '/');

        if (! is_dir($root)) {
            return;
        }

        // `tmp` is always a local disk, so the metadata comes straight off the
        // filesystem: `allFiles()` + `lastModified()` + `size()` cost three API
        // round trips per file for three `stat()` answers.
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            // The disk API takes the path relative to its root.
            $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            try {
                if ($file->getMTime() >= $cutoff) {
                    continue;
                }

                $size = (int) $file->getSize();
                if ($disk->delete($path)) {
                    $deleted++;
                    $bytes += $size;
                }
            } catch (Throwable $e) {
                Log::warning('Temporary storage cleanup skipped a file', [
                    'path' => $path,
                    'exception' => $e->getMessage(),
                    'request_id' => $this->requestId(),
                ]);
            }
        }

        if ($deleted === 0) {
            return;
        }

        // No causer: a cleanup is the system's own action, also when a web
        // request happened to cross the threshold and dispatched it. The
        // `SYSTEM` marker is what the viewer reads; a null alone renders as
        // "Anonymous".
        SystemSetting::query()->firstOrFail()->audit('temp_storage.cleaned', null, [
            'causer' => 'SYSTEM',
            'source' => 'system',
            'deleted_count' => $deleted,
            'bytes_freed' => $bytes,
            'retention_hours' => $hours,
        ]);
    }
}
