<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * The one place that knows what an API envelope's `meta` block is.
 *
 * `Controller::respond()` and the exception handler in `bootstrap/app.php` both
 * emit it, and before this class existed they each built the two keys by hand —
 * which is how an error could answer without `meta` while the 422 beside it had
 * one. A caller cannot tell "the server is older" from "this error is special".
 *
 * `message` first, `code` second, `meta` last, matching
 * `docs/base/api/error-contract.md`; `$extra` is merged on top for the payloads
 * that legitimately add a key (`errors` on 422, `retry_after_seconds` on 429,
 * `email`/`verified` on the unverified-email refusal).
 *
 * Not a base controller method: the exception handler has no controller.
 */
final class ApiError
{
    /**
     * The documented error body as a PHPDoc type.
     *
     * Lives here so an endpoint that declares a response with `#[Response]` names
     * the same shape the handler emits instead of retyping it, and so a change to
     * the envelope has one place to start.
     */
    public const DOC_TYPE = 'array{message: string, code: string, meta: array{request_id: string, timestamp: string}}';

    /**
     * The keys every response carries for correlation.
     *
     * `request_id` is bound by `GenerateRequestCorrelationId`, which is global
     * middleware, so it is set for anything that can answer — including a
     * request that failed before reaching a route.
     *
     * @return array{request_id: string, timestamp: string}
     */
    public static function meta(): array
    {
        return [
            'request_id' => app('request_id'),
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Build an error body in the documented envelope.
     *
     * @param  array<string, mixed>  $extra  Additional top-level keys.
     */
    public static function make(string $message, string $code, int $status, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'message' => $message,
            'code' => $code,
            'meta' => self::meta(),
        ], $extra), $status);
    }
}
