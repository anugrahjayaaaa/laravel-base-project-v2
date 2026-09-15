<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HealthCheckEndpointTest extends TestCase
{
    public function test_health_check_returns_ok_status(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/json');
    }

    public function test_health_check_response_structure(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'timestamp',
                'checks' => [
                    'database',
                    'cache',
                    'queue',
                    'storage',
                ],
            ]);
    }

    public function test_health_check_status_is_ok_when_all_pass(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonPath('checks.cache', 'ok')
            ->assertJsonPath('checks.queue', 'ok')
            ->assertJsonPath('checks.storage', 'ok');
    }

    public function test_health_check_timestamp_is_iso8601(): void
    {
        $response = $this->getJson('/api/v1/health');

        $timestamp = $response->json('timestamp');
        $this->assertIsString($timestamp);
        $this->assertNotEmpty($timestamp);

        // Should be parseable by Carbon
        \Illuminate\Support\Carbon::parse($timestamp);
    }

    protected function tearDown(): void
    {
        Cache::forget('__health_check__');
        $disk = config('filesystems.default');
        \Illuminate\Support\Facades\Storage::disk($disk)->delete('__health_check__');

        parent::tearDown();
    }
}
