<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Base API controller with JSON response helper and activity audit.
 */
abstract class Controller
{
    /**
     * Return a standardized JSON response.
     *
     * @param  string  $message
     * @param  int  $status
     * @param  array  $data
     * @return JsonResponse
     */
    protected function respond(
        string $message,
        int $status = 200,
        array $data = []
    ): JsonResponse {
        return response()->json([
            'data' => $data ?: ['message' => $message],
            'meta' => [
                'request_id' => app('request_id'),
                'timestamp' => now()->toIso8601String(),
            ],
        ], $status);
    }

    /**
     * Log an activity audit event via spatie activitylog.
     *
     * @param  string  $event
     * @param  Model|null  $subject
     * @param  User|null  $causer
     * @param  array  $properties
     * @return void
     */
    protected function audit(
        string $event,
        ?Model $subject = null,
        ?User $causer = null,
        array $properties = []
    ): void {
        $source = request()->is('api/*') ? 'api' : 'web';

        $activity = activity();

        if ($subject !== null) {
            $activity->performedOn($subject);
        }

        if ($causer !== null) {
            $activity->causedBy($causer);
        }

        $activity->withProperties(array_merge(['source' => $source], $properties));

        $activity->log($event);
    }

    /**
     * Bulk-insert activity log records for multiple subjects.
     *
     * @param  string  $event
     * @param  array  $records
     * @param  User|null  $causer
     * @param  string  $type
     * @return void
     */
    protected function bulkAudit(string $event, array $records, ?User $causer = null, string $type = 'web'): void
    {
        if (empty($records)) {
            return;
        }

        $causerType = User::class;
        $causerId = $causer?->id;
        $now = now()->toDateTimeString();

        $rows = array_map(function ($record) use ($event, $causerType, $causerId, $type, $now) {
            $props = isset($record['properties']) && $record['properties']
                ? array_merge(['source' => $type], $record['properties'])
                : ['source' => $type];

            return [
                'log_name' => 'default',
                'description' => $event,
                'subject_type' => User::class,
                'subject_id' => $record['subject_id'],
                'causer_type' => $causerType,
                'causer_id' => $causerId,
                'properties' => json_encode($props),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $records);

        DB::table(config('activitylog.table_name', 'activity_log'))->insert($rows);
    }
}
