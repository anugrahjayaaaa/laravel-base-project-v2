<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorrelationIdMiddlewareTest extends TestCase
{
    /**
     * The X-Request-ID header is generated when the client does not provide one.
     */
    public function test_generates_correlation_id_when_not_provided(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertHeader('X-Request-ID');

        $header = $response->headers->get('X-Request-ID');
        $this->assertNotEmpty($header);
        // UUIDv4 format
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $header
        );
    }

    /**
     * The client can supply its own X-Request-ID which is respected and echoed back.
     */
    public function test_respects_client_supplied_correlation_id(): void
    {
        $response = $this->withHeaders([
            'X-Request-ID' => 'client-trace-12345',
        ])->get('/');

        $response->assertStatus(200);
        $response->assertHeader('X-Request-ID', 'client-trace-12345');
    }

    /**
     * The correlation ID is available globally via the container.
     */
    public function test_correlation_id_available_in_container(): void
    {
        $this->withHeaders([
            'X-Request-ID' => 'container-check-67890',
        ])->get('/');

        $this->assertEquals('container-check-67890', app('request_id'));
    }
}
