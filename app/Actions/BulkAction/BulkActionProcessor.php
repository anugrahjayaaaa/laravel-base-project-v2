<?php

namespace App\Actions\BulkAction;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BulkActionProcessor
{
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
            return ['count' => 0, 'label' => 'No valid items'];
        }

        $auditRecords = [];

        DB::transaction(function () use ($action, $validIds, $validItems, $handler, $causer, &$auditRecords) {
            $handler->executeBulk($action, $validIds);

            if (in_array($action, $handler->getSessionInvalidationActions())) {
                $handler->batchInvalidateSessions($validIds);
            }

            foreach ($validItems as $item) {
                $auditRecords[] = ['subject_id' => $item->id];
            }

            if (!empty($auditRecords)) {
                $type = request()->is('api/*') ? 'api' : 'web';
                $this->bulkAudit($handler->getAuditEvent($action), $auditRecords, $causer, $type);
            }

            foreach ($handler->getCacheKeys() as $key) {
                Cache::forget($key);
            }
        });

        return ['count' => $count, 'label' => $handler->getAuditLabel($action)];
    }

    private function bulkAudit(string $event, array $records, ?User $causer, string $type): void
    {
        if (empty($records)) {
            return;
        }

        $now = now()->toDateTimeString();
        $causerType = User::class;
        $causerId = $causer?->id;
        $table = config('activitylog.table_name', 'activity_log');

        $rows = array_map(function ($record) use ($event, $causerType, $causerId, $type, $now) {
            $props = isset($record['properties']) && $record['properties']
                ? array_merge(['source' => $type], $record['properties'])
                : ['source' => $type];

            return [
                'log_name' => 'default',
                'description' => $event,
                'subject_type' => $causerType,
                'subject_id' => $record['subject_id'],
                'causer_type' => $causerType,
                'causer_id' => $causerId,
                'properties' => json_encode($props),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $records);

        DB::table($table)->insert($rows);
    }
}