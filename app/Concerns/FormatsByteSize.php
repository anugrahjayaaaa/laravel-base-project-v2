<?php

namespace App\Concerns;

trait FormatsByteSize
{
    /**
     * Bytes as a short human label, or "unknown" when the disk cannot answer.
     */
    private function bytesLabel(?int $bytes): string
    {
        if ($bytes === null) {
            return 'unknown';
        }

        foreach (['GB' => 1024 ** 3, 'MB' => 1024 ** 2, 'KB' => 1024] as $unit => $size) {
            if ($bytes >= $size) {
                return round($bytes / $size, 1) . ' ' . $unit;
            }
        }

        return $bytes . ' B';
    }
}
