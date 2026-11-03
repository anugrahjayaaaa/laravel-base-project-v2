<?php

namespace App\Services\Storage;

use App\Exceptions\StorageCapacityExceededException;
use App\Jobs\CleanTemporaryStorageJob;
use App\Models\SystemSetting;
use FilesystemIterator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Measures the active disk and refuses a write that would fill it.
 *
 * ## Why an exception and not a middleware
 *
 * Capacity is a property of the write, not of the route: a 200-byte settings
 * save and a 200 MB export both go through different code paths. The guard is
 * called where the bytes are about to be written, which is the only place that
 * knows how large they are.
 *
 * ## Coverage is per boundary
 *
 * It checks only the disk it is handed, and only where a caller invokes it —
 * there is no global check. The per-boundary map and the rule for new write
 * boundaries live in `docs/base/infrastructure/storage.md` § Guard coverage.
 *
 * ## Local and S3 are not the same measurement
 *
 * A local disk has a real total (`disk_total_space`), and its used bytes are
 * the ones stored under the disk root. S3 does not — there is no "bucket
 * capacity" API — so when `storage_max_capacity_mb` is set it becomes the
 * denominator, and without it the gauge reports used bytes with no total.
 * Fabricating one would be a progress bar that lies.
 */
class StorageCapacityGuard
{
    /**
     * Refuse before the disk is actually full, so logs, the database and the
     * worker's own temporary files still have somewhere to go.
     */
    public const HARD_LIMIT_PERCENT = 95;

    /** S3 has no cheap usage endpoint; one probe per five minutes is enough. */
    private const S3_PROBE_TTL_SECONDS = 300;

    /** The gauge is polled by every health check; a walk per minute is enough. */
    private const USED_BYTES_CACHE_SECONDS = 60;

    /** One warning per hour, not one per upload. */
    private const WARNING_THROTTLE_SECONDS = 3600;

    /**
     * Used / available / total bytes for the active disk, measured now.
     *
     * `used` counts the bytes actually stored under the disk's root (uploads,
     * exports; backups and logs live outside it) for local disks; `total` is
     * the physical total or the configured quota, whichever is smaller. S3 has
     * no local root, so its used bytes come from the bucket probe. `used` and
     * `total` are null when the platform cannot answer (an S3 probe without
     * credentials, a local root that does not exist yet). Callers must render
     * "unknown" rather than treat null as zero.
     *
     * @return array{disk: string, used: int|null, available: int|null, total: int|null, quota: int|null, percent: float|null, known: bool}
     */
    public function metrics(?string $disk = null): array
    {
        return $this->build($disk ?? (string) config('filesystems.default', 'local'));
    }

    /**
     * The same figures for a gauge, at most a minute old.
     *
     * The health endpoint and the settings page both call this on every poll,
     * and a walk over the disk root per poll is the expensive part. Never use
     * this for a write decision: a minute-old number can be under the limit
     * while the disk is already past it.
     *
     * @return array{disk: string, used: int|null, available: int|null, total: int|null, quota: int|null, percent: float|null, known: bool}
     */
    public function cachedMetrics(?string $disk = null): array
    {
        $disk ??= (string) config('filesystems.default', 'local');

        return $this->build($disk, $this->cachedUsage($disk));
    }

    /**
     * @param  array{0: int|null, 1: int|null}|null  $usage
     * @return array{disk: string, used: int|null, available: int|null, total: int|null, quota: int|null, percent: float|null, known: bool}
     */
    private function build(string $disk, ?array $usage = null): array
    {
        $quotaMb = max(0, SystemSetting::getInt('storage_max_capacity_mb', 0));
        $quota = $quotaMb > 0 ? $quotaMb * 1024 * 1024 : null;

        [$used, $physicalTotal] = $usage ?? $this->usage($disk);

        $total = $quota ?? $physicalTotal;
        if ($quota !== null && $physicalTotal !== null) {
            $total = min($quota, $physicalTotal);
        }

        $percent = ($used !== null && $total !== null && $total > 0)
            ? round($used / $total * 100, 1)
            : null;

        return [
            'disk' => $disk,
            'used' => $used,
            'available' => ($used !== null && $total !== null) ? max(0, $total - $used) : null,
            'total' => $total,
            'quota' => $quota,
            'percent' => $percent,
            'known' => $used !== null && $total !== null,
        ];
    }

    /**
     * Refuse the write when it would cross the quota, or when the disk is
     * already past the hard limit.
     *
     * An unknown measurement cannot refuse: refusing every write on a disk we
     * cannot read would take the application down for a monitoring failure.
     *
     * @throws StorageCapacityExceededException
     */
    public function assertCanStore(int $bytes = 0, ?string $disk = null): void
    {
        $metrics = $this->metrics($disk);

        if (! $metrics['known'] || $metrics['used'] === null || $metrics['total'] === null) {
            return;
        }

        $projected = $metrics['used'] + max(0, $bytes);
        $full = $projected > $metrics['total']
            || ($metrics['percent'] ?? 0) >= self::HARD_LIMIT_PERCENT;

        if ($full) {
            $this->warn($metrics);

            throw new StorageCapacityExceededException($metrics);
        }

        $warningAt = SystemSetting::getInt('storage_warning_threshold_percent', 80);

        if (($metrics['percent'] ?? 0) >= $warningAt) {
            $this->warn($metrics);
        }
    }

    /**
     * Bytes used and the physical total for one disk.
     *
     * Local `used` is the bytes stored under the disk root — uploads, exports,
     * backups — so the gauge answers "what is in /storage" rather than the whole
     * volume the project happens to sit on. The physical total comes from
     * `disk_total_space` and still feeds the quota as its ceiling.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function usage(string $disk): array
    {
        if ($disk === 's3') {
            return [$this->s3UsedBytes($disk), null];
        }

        try {
            $root = Storage::disk($disk)->path('');
            $physicalTotal = @disk_total_space($root);

            if ($physicalTotal === false) {
                return [null, null];
            }

            return [$this->storedBytes($disk), (int) $physicalTotal];
        } catch (Throwable) {
            return [null, null];
        }
    }

    /**
     * Cache the walk, not the arithmetic: the quota still comes from settings
     * on every call, so lowering it shows up on the next poll.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function cachedUsage(string $disk): array
    {
        if ($disk === 's3') {
            return $this->usage($disk);
        }

        try {
            $root = Storage::disk($disk)->path('');
        } catch (Throwable) {
            return [null, null];
        }

        // Hashed because the root is an absolute path; two deployments can share
        // one cache store without colliding or leaking a server path as a key.
        return Cache::remember(
            'storage.used.'.$disk.':'.md5($root),
            self::USED_BYTES_CACHE_SECONDS,
            fn (): array => $this->usage($disk)
        );
    }

    /**
     * Sum the file sizes under the disk root.
     *
     * A raw iterator, not `Storage::allFiles()`: the Flysystem path builds one
     * string per file and adds a `size()` call each (`stat()` again), which
     * measured at over five times the cost of walking the same tree directly.
     */
    private function storedBytes(string $disk): int
    {
        $root = Storage::disk($disk)->path('');

        if (! is_dir($root)) {
            return 0;
        }

        $bytes = 0;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            $bytes += (int) $file->getSize();
        }

        return $bytes;
    }

    /**
     * Sum every object in the bucket.
     *
     * Cached because the probe lists the bucket: at a few thousand objects that
     * is fine once every five minutes, and unacceptable once per page load.
     */
    private function s3UsedBytes(string $disk): ?int
    {
        try {
            return Cache::remember(
                'storage.s3.used.'.$disk,
                self::S3_PROBE_TTL_SECONDS,
                function () use ($disk): int {
                    $adapter = Storage::disk($disk)->getAdapter();
                    $client = $adapter->getClient();
                    $used = 0;

                    foreach ($client->getPaginator('ListObjectsV2', ['Bucket' => $adapter->getBucket()]) as $page) {
                        foreach ($page['Contents'] ?? [] as $object) {
                            $used += (int) ($object['Size'] ?? 0);
                        }
                    }

                    return $used;
                }
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Record the crossing once per hour and kick off a `tmp` cleanup.
     *
     * The audit subject is the settings row, matching the settings action. The
     * same throttle gates the job dispatch so a busy upload path does not flood
     * the sweeps queue. A failure to record or dispatch must not replace the
     * refusal the caller needs to see, hence the catch.
     *
     * @param  array<string, mixed>  $metrics
     */
    private function warn(array $metrics): void
    {
        try {
            if (! Cache::add('storage.capacity.warning', true, now()->addSeconds(self::WARNING_THROTTLE_SECONDS))) {
                return;
            }

            SystemSetting::query()->firstOrFail()->audit('storage.capacity_warning', auth()->user(), [
                // A crossing detected outside a request (a worker or a console
                // command) has no actor to name; it is the guard acting.
                ...(auth()->user() === null ? ['causer' => 'SYSTEM'] : []),
                'disk' => $metrics['disk'],
                'used_bytes' => $metrics['used'],
                'total_bytes' => $metrics['total'],
                'percent' => $metrics['percent'],
            ]);

            // The caller may be inside its own transaction; a sweep dispatched
            // from a rollback would clean a `tmp` the save never used.
            CleanTemporaryStorageJob::dispatch()->afterCommit();
        } catch (Throwable) {
            // Reporting must never block the write path or replace its error.
        }
    }
}
