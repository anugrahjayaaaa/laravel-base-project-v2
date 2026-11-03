<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The active storage disk cannot take another write.
 *
 * Raised by `App\Services\Storage\StorageCapacityGuard` before an upload or a
 * generated file is written. The handler renders it as 507 `STORAGE_FULL` on the
 * API and as a redirect-with-error on the web, so the caller is told the disk is
 * full rather than that the server broke.
 */
class StorageCapacityExceededException extends RuntimeException
{
    /**
     * @param  array<string, int|float|null>  $metrics  The measurement that refused the write.
     */
    public function __construct(
        public readonly array $metrics = [],
        string $message = 'Storage capacity exceeded.',
    ) {
        parent::__construct($message);
    }
}
