<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Facades\Activity;
use Illuminate\Database\Eloquent\Model;

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
     * Log an audit event via the spatie/activitylog facade.
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
}
