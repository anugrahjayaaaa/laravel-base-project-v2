<?php

namespace App\Jobs\Concerns;

use App\Models\User;

/**
 * Provides the common audit entry point for system-triggered jobs.
 */
trait AuditsSystemActivity
{
    /**
     * Record a system audit event against the affected user.
     *
     * System jobs do not have an authenticated causer. Keep the database
     * causer nullable and expose SYSTEM in the audit properties instead of
     * fabricating a user record.
     *
     * @param array<string, mixed> $properties
     */
    protected function audit(User $user, string $event, array $properties = []): void
    {
        $user->audit($event, null, array_merge([
            'causer' => 'SYSTEM',
            'source' => 'system',
        ], $properties));
    }
}
