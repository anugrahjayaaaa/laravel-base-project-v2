<?php

namespace App\Services\Backup;

use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Upper-bound size of a backup, before it is written.
 *
 * The artifact is compressed, so the raw figure is deliberately pessimistic:
 * it can abort a backup that would have fit, never start one that does not.
 *
 * The file half measures `storage/app` minus the backup destination and the
 * package's scratch directory — those are not part of the source set. Counting
 * them would make the estimate grow with every backup until the pre-check
 * refused forever.
 *
 * The database half asks MySQL for `data_length + index_length`. Other drivers
 * answer zero: SQLite, the test driver, has no equivalent and the file half is
 * the only honest measurement there.
 */
class SystemBackupSizeEstimator
{
    /** Top-level directories under `storage/app` that are not backup source. */
    private const EXCLUDED_DIRECTORIES = ['backups', 'backup-temp'];

    /**
     * @return array{files: int, database: int, total: int}
     */
    public function estimate(): array
    {
        $files = $this->filesBytes();
        $database = $this->databaseBytes();

        return [
            'files' => $files,
            'database' => $database,
            'total' => $files + $database,
        ];
    }

    public function filesBytes(): int
    {
        $root = storage_path('app');

        if (! is_dir($root)) {
            return 0;
        }

        $bytes = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = ltrim(substr($file->getPathname(), strlen($root)), DIRECTORY_SEPARATOR);

            if (in_array(explode(DIRECTORY_SEPARATOR, $relative)[0], self::EXCLUDED_DIRECTORIES, true)) {
                continue;
            }

            $bytes += (int) $file->getSize();
        }

        return $bytes;
    }

    public function databaseBytes(): int
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return 0;
        }

        $row = DB::selectOne(
            'SELECT COALESCE(SUM(data_length + index_length), 0) AS total
             FROM information_schema.TABLES
             WHERE table_schema = DATABASE()'
        );

        return (int) ($row->total ?? 0);
    }

    /**
     * Free bytes on a local destination, or null when the platform cannot
     * answer (an S3 disk, a missing root). Null must never mean "full".
     */
    public function availableBytes(string $disk = 'backups'): ?int
    {
        try {
            $config = config('filesystems.disks.'.$disk);

            if (($config['driver'] ?? null) !== 'local') {
                return null;
            }

            $free = @disk_free_space(Storage::disk($disk)->path(''));

            return $free === false ? null : (int) $free;
        } catch (Throwable) {
            return null;
        }
    }
}
