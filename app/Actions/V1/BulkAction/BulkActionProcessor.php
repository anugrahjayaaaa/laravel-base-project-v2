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
                'auditRecords' => [],
                'auditEvent' => null
            ];
        }

        $auditRecords = [];

        DB::transaction(function () use ($action, $validIds, $validItems, $handler, &$auditRecords) {
            $handler->executeBulk($action, $validIds);

            if (in_array($action, $handler->getSessionInvalidationActions())) {
                $handler->batchInvalidateSessions($validIds);
            }

            foreach ($validItems as $item) {
                $auditRecords[] = ['subject_id' => $item->id];
            }

            foreach ($handler->getCacheKeys() as $key) {
                Cache::forget($key);
            }
        });

        return [
            'count' => $count,
            'label' => $handler->getAuditLabel($action),
            'auditRecords' => $auditRecords,
            'auditEvent' => $handler->getAuditEvent($action),
        ];
    }
}
