<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generate and propagate a correlation/request ID.
 *
 * Generates a UUIDv4 correlation ID on every request (unless the client
 * supplies one via the X-Request-ID header), makes it globally available
 * for structured logging via Log::withContext, and echoes it back in the
 * response header.
 *
 * @see docs/base/infrastructure/logging.md §8 Request / Correlation ID
 * @see docs/planning/task-tracker.md FOUND-008
 */
class GenerateRequestCorrelationId
{
    /**
     * The request header used to pass/propagate the correlation ID.
     */
    public const REQUEST_ID_HEADER = 'X-Request-ID';

    /**
     * Get or create the correlation ID for the given request.
     */
    protected function resolveId(Request $request): string
    {
        $provided = $request->header(self::REQUEST_ID_HEADER);

        if (is_string($provided) && $provided !== '') {
            return $provided;
        }

        return (string) Str::uuid();
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->resolveId($request);

        // Make available application-wide without a static container
        app()->instance('request_id', $requestId);

        // Automatically attach to all structured log calls
        logger()->withContext(['request_id' => $requestId]);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set(self::REQUEST_ID_HEADER, $requestId);

        return $response;
    }
}
