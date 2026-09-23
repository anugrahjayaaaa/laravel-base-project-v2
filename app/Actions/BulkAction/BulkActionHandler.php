<?php

namespace App\Actions\BulkAction;

use Illuminate\Database\Eloquent\Collection;

interface BulkActionHandler
{
    /**
     * Filter and return valid items for the given action.
     *
     * @param  array<int>  $ids
     * @return Collection<int, \Illuminate\Database\Eloquent\Model>
     */
    public function getValidItems(array $ids, string $action): Collection;

    /**
     * Execute the bulk operation for the given action on the valid IDs.
     *
     * @param  array<int>  $ids
     */
    public function executeBulk(string $action, array $ids): void;

    /**
     * Get the technical audit event name for the activity log.
     */
    public function getAuditEvent(string $action): string;

    /**
     * Get the human-readable label for the action result.
     */
    public function getAuditLabel(string $action): string;

    /**
     * Get cache keys to invalidate after the bulk operation.
     *
     * @return string[]
     */
    public function getCacheKeys(): array;

    /**
     * Batch invalidate sessions/tokens for the given IDs.
     * Only called for actions that require session invalidation.
     *
     * @param  array<int>  $ids
     */
    public function batchInvalidateSessions(array $ids): void;

    /**
     * Get the list of actions that require session invalidation.
     *
     * @return string[]
     */
    public function getSessionInvalidationActions(): array;
}
