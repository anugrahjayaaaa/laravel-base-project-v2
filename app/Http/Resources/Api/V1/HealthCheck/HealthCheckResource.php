<?php

namespace App\Http\Resources\Api\V1\HealthCheck;

use Illuminate\Http\Resources\Json\JsonResource;

class HealthCheckResource extends JsonResource
{
    /**
     * The resource does not need to be wrapped in a "data" key.
     */
    public static $wrap = null;

    /**
     * @param  \Illuminate\Http\Request  $request
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
