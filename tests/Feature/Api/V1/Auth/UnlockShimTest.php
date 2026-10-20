<?php

namespace Tests\Feature\Api\V1\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The `/auth/unlock` redirect shim is gone, and this is why a test owns it.
 *
 * It forwarded to `api.v1.users.unlock` with `request()->route('user')`, but
 * its own route has no `{user}` — so the parameter was always null and URL
 * generation threw: the endpoint answered 500 to everyone, and it was the only
 * route in the surface that could not work. It existed as backwards
 * compatibility for an address that was never published (v1 is unreleased), and
 * the comment beside it claimed Scramble could not see a closure, which was
 * also false — it appeared in the document as a 200 with no body.
 *
 * The current address is `POST /api/v1/users/{user}/unlock`, which needs the id
 * the shim never had. Re-adding a redirect means deciding where the id comes
 * from first.
 */
class UnlockShimTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_removed_shim_is_a_404_not_a_server_error(): void
    {
        $response = $this->postJson('/api/v1/auth/unlock');

        $response->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $this->assertFalse(app('router')->has('api.v1.auth.unlock'));
    }
}
