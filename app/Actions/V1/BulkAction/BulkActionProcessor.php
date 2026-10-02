<?php

namespace App\Actions\V1\BulkAction;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates a bulk action: validates items, executes, audits, invalidates cache.
 */
class BulkActionProcessor
{
    /**
     * Run a bulk action through the given handler.
     *
     * @param  string            $action
     * @param  array<int>        $ids
     * @param  User              $causer
     * @param  BulkActionHandler $handler
     * @return array             ['count' => int, 'label' => string]
     */
    public function run(
        string $action,
        array $ids,
        User $causer,
        BulkActionHandler $handler,
    ): array {
        $validItems = $handler->getValidItems($ids, $action);
        $validIds = $validItems->pluck('id')->toArray();
        $count = count($validIds);

        if ($count === 0) {
            return [
                'count' => 0,
                'label' => 'No valid items',
            ];
        }

        DB::transaction(function () use ($action, $validIds, $handler) {
            $handler->executeBulk($action, $validIds);

            if (in_array($action, $handler->getSessionInvalidationActions())) {
                $handler->batchInvalidateSessions($validIds);
            }

            foreach ($handler->getCacheKeys() as $key) {
                Cache::forget($key);
            }
        });

        // No `auditRecords` / `auditEvent` here any more. They existed only to
        // feed `Controller::bulkAudit()`, which is deleted; every handler now
        // loops actions that write their own rows, so there is nothing for a
        // controller to add. `getAuditEvent()` stays on the interface because
        // RoleBulkActionHandler is the reference implementation of returning
        // `''`, and a handler that ever needs an aggregate row has one place to
        // say so.
        return [
            'count' => $count,
            'label' => $handler->getAuditLabel($action),
        ];
    }
}
