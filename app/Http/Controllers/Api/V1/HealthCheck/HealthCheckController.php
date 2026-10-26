<?php

namespace App\Http\Controllers\Api\V1\HealthCheck;

use App\Http\Resources\Api\V1\HealthCheck\HealthCheckResource;
use App\Services\HealthCheck\HealthCheckService;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Health check controller, returns system health status.
 */
class HealthCheckController
{
    /**
     * Run health checks and return results.
     *
     * Unwrapped `{status, timestamp, checks}`, not the API envelope — a monitor
     * reads `status`. 503 is the failing probe, same shape.
     *
     * @param  Request  $request
     * @param  HealthCheckService  $healthCheck
     * @return JsonResponse
     */
    // The response is declared explicitly because the method returns a prepared
    // JsonResponse, so the generator cannot follow the resource and infers a
    // bare string. Remove these two lines only if the controller starts
    // returning the resource itself.
    #[Response(status: 200, type: 'array{status: string, timestamp: string, checks: array<string, string>}')]
    #[Response(status: 503, type: 'array{status: string, timestamp: string, checks: array<string, string>}')]
    public function __invoke(
        Request $request,
        HealthCheckService $healthCheck
    ): JsonResponse {
        $checks = $healthCheck->check();

        return (new HealthCheckResource($checks))
            ->response()
            ->setStatusCode($healthCheck->isHealthy($checks) ? 200 : 503);
    }
}
