<?php

namespace App\Http\Controllers;

use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use LogicException;

/**
 * Base controller with the two JSON envelope helpers.
 *
 * `respond()` for successes (message/data + `meta`), `respondError()` for the
 * documented error body (`message`/`code`/`meta`). Both take their `meta` block
 * from `ApiError`, which the exception handler also uses — one definition, so an
 * error cannot drift from the success beside it.
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
        if ($status >= 400) {
            throw new LogicException('respond() is a success helper; use respondError() for refusals.');
        }

        return response()->json([
            'data' => $data ?: ['message' => $message],
            'meta' => ApiError::meta(),
        ], $status);
    }

    /**
     * Return an error in the shape `docs/base/api/error-contract.md` documents.
     *
     * Use this, not `respond($message, 4xx)`, for anything a client is meant to
     * branch on: `respond()` puts the message under `data`, which is the success
     * envelope and carries no `code`. `respond()` refuses a 4xx status outright
     * so a refusal cannot drift back into the success envelope.
     *
     * @param  array<string, mixed>  $extra  Additional top-level keys.
     */
    protected function respondError(
        string $message,
        string $code,
        int $status,
        array $extra = []
    ): JsonResponse {
        return ApiError::make($message, $code, $status, $extra);
    }
}
