<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
     * ## Transitional — prefer the action
     *
     * Only the Profile controllers still call this; every other domain audits
     * from inside its action (see `Auditable::audit()`). Migrating Profile is
     * AUD-006 in `docs/qa/remediation-tracker.md`, and deleting this method
     * before then would leave those mutations unaudited.
     *
     * It delegates its context to `User::auditContext()` rather than deriving
     * its own, so a row written here carries the same `source`, `ip` and
     * `user_agent` as one written by an action. When this method goes, nothing
     * about the context moves.
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
        $activity = activity();

        if ($subject !== null) {
            $activity->performedOn($subject);
        }

        if ($causer !== null) {
            $activity->causedBy($causer);
        }

        $activity->withProperties(array_merge(User::auditContext(), $properties));

        $activity->log($event);
    }

    /**
     * Bulk-insert activity log records for multiple subjects.
     *
     * ## `event`, not just `description`
     *
     * The raw insert below bypasses Spatie's builder, so nothing fills `event`
     * for us. It used to leave it NULL while writing `description` — and every
     * reader in this codebase filters on `event` (`where('event', ...)` in the
     * role, user and feature tests alike). A bulk row was therefore present in
     * the table and invisible to every filter and viewer built on that column.
     * One line here closes all 7 bulk actions on both web and API, rather than
     * seven fixes.
     *
     * `batch_uuid` is one UUID for the whole request, which is what makes the
     * rows correlatable as the single user action they were. Spatie does the
     * same for its own batch logging.
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
        $batchUuid = (string) Str::uuid();

        $rows = array_map(function ($record) use ($event, $causerType, $causerId, $type, $now, $batchUuid) {
            $props = isset($record['properties']) && $record['properties']
                ? array_merge(['source' => $type], $record['properties'])
                : ['source' => $type];

            return [
                'log_name' => 'default',
                'description' => $event,
                'event' => $event,
                'subject_type' => User::class,
                'subject_id' => $record['subject_id'],
                'causer_type' => $causerType,
                'causer_id' => $causerId,
                'properties' => json_encode($props),
                'batch_uuid' => $batchUuid,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $records);

        DB::table(config('activitylog.table_name', 'activity_log'))->insert($rows);
    }
}
