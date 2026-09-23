<?php

namespace App\Http\Controllers\Api\V1\HealthCheck;

use App\Http\Resources\Api\V1\HealthCheck\HealthCheckResource;
use App\Services\HealthCheck\HealthCheckService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Health check controller — returns system health status.
 */
class HealthCheckController
{
    /**
     * Run health checks and return results.
     *
     * @param  Request  $request
     * @param  HealthCheckService  $healthCheck
     * @return JsonResponse
     */
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
