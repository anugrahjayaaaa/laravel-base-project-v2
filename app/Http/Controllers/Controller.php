<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Base API controller with the JSON response helper.
 *
 * Deliberately holds no audit helper. Both that used to live here —
 * `audit()` and `bulkAudit()` — are gone. The controllers that called them get
 * their records from the action performing the mutation, and
 * `Auditable::audit()` / `Auditable::auditBulk()` are the only entry points
 * left. A controller that needs to record something is a controller writing
 * the wrong layer.
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
}
