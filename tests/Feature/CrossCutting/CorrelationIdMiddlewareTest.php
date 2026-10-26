<?php

namespace Tests\Feature\CrossCutting;

use Illuminate\Support\Str;
use Tests\TestCase;

class CorrelationIdMiddlewareTest extends TestCase
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
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
        $this->assertMatchesRegularExpression(self::UUID_PATTERN, $header);
    }

    /**
     * A client UUID is trusted as-is — it is the shape we generate ourselves.
     */
    public function test_accepts_a_client_supplied_uuid(): void
    {
        $uuid = (string) Str::uuid();

        $this->withHeaders(['X-Request-ID' => $uuid])
            ->get('/')
            ->assertHeader('X-Request-ID', $uuid);
    }

    /**
     * 64 characters is the ceiling, and it is inclusive.
     */
    public function test_accepts_a_client_supplied_id_of_exactly_64_characters(): void
    {
        $id = str_repeat('a', 64);

        $this->withHeaders(['X-Request-ID' => $id])
            ->get('/')
            ->assertHeader('X-Request-ID', $id);
    }

    /**
     * An oversized id is replaced, not truncated or echoed: it would be copied
     * into every log line and every audit row for this request.
     */
    public function test_replaces_an_oversized_client_supplied_id(): void
    {
        $response = $this->withHeaders(['X-Request-ID' => str_repeat('a', 65)])->get('/');

        $this->assertNotSame(str_repeat('a', 65), $response->headers->get('X-Request-ID'));
        $this->assertMatchesRegularExpression(
            self::UUID_PATTERN,
            $response->headers->get('X-Request-ID')
        );
    }

    /**
     * Characters that are not part of an opaque id are refused. A value with a
     * line break in it would forge an entry in a line-based log.
     */
    public function test_replaces_a_client_supplied_id_with_unsafe_characters(): void
    {
        $response = $this->withHeaders(['X-Request-ID' => 'trace 123'])->get('/');

        $this->assertNotSame('trace 123', $response->headers->get('X-Request-ID'));
        $this->assertMatchesRegularExpression(
            self::UUID_PATTERN,
            $response->headers->get('X-Request-ID')
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
