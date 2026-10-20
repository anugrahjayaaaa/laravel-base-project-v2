<?php

namespace App\Http\Resources\Api\V1\HealthCheck;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The health probe, for API clients.
 *
 * Wraps an array of check => 'ok'|'fail', not a model — the probe has no row
 * behind it. `$wrap = null` keeps the shape flat, which is what a health
 * endpoint is for: a monitor reads the top-level `status`.
 *
 * The documented schema is declared on `HealthCheckController` instead: this
 * class has no model for the generator to infer, and a `@property-read` naming
 * a fictional one would only silence a warning.
 */
class HealthCheckResource extends JsonResource
{
    /**
     * The resource does not need to be wrapped in a "data" key.
     */
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array{status: 'ok'|'fail', timestamp: string, checks: array<string, string>}
     */
    public function toArray($request): array
    {
        $checks = (array) $this->resource;

        return [
            'status'    => ! in_array('fail', $checks, true) ? 'ok' : 'fail',
            'timestamp' => now()->toIso8601String(),
            'checks'    => $checks,
        ];
    }
}
