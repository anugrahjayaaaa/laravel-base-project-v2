<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Facades\Activity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

abstract class Controller
{
    /**
     * Return a consistent JSON envelope for simple responses.
     *
     * Use this instead of creating a JSON Resource class when the response
     * does not require field transformation, conditional inclusion, or
     * relationship serialization. See docs/base/api/response-contract.md.
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
     * Log audit events via the spatie/activitylog facade.
     *
     * - Actions that perform mutations log audit within the action itself.
     * - Controllers log audit directly for thin operations.
     * - Never use model observers for audit. See docs/base/architecture/
     *   application-components.md §Action/Service.
     *
     * @param string $event        Snake_case event name (e.g. 'auth.login').
     * @param Model|null $subject  Model this action was performed on (optional).
     * @param User|null $causer    User who caused the action (null for system).
     * @param array $properties    Additional context (IP, user_agent, etc.).
     */
    protected function audit(
        string $event,
        ?Model $subject = null,
        ?User $causer = null,
        array $properties = []
    ): void {
        $activity = activity();

        if ($subject !== null) {
            $activity->performedOn($subject);
        }

        if ($causer !== null) {
            $activity->causedBy($causer);
        }

        if (!empty($properties)) {
            $activity->withProperties($properties);
        }

        $activity->log($event);
    }

    /**
     * Bulk log audit events via direct DB insert (bypass spatie facade).
     * Used for bulk operations where per-user facade calls are too slow.
     *
     * @param string $event    Snake_case event name.
     * @param array $records   [['subject_id' => int, 'properties' => []], ...]
     * @param User|null $causer User who caused the action.
     */
    protected function bulkAudit(string $event, array $records, ?User $causer = null): void
    {
        if (empty($records)) {
            return;
        }

        $causerType = User::class;
        $causerId = $causer?->id;
        $now = now()->toDateTimeString();

        $rows = array_map(function ($record) use ($event, $causerType, $causerId, $now) {
            return [
                'log_name' => 'default',
                'description' => $event,
                'subject_type' => User::class,
                'subject_id' => $record['subject_id'],
                'causer_type' => $causerType,
                'causer_id' => $causerId,
                'properties' => isset($record['properties']) && $record['properties'] ? json_encode($record['properties']) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $records);

        DB::table(config('activitylog.table_name', 'activity_log'))->insert($rows);
    }
}
