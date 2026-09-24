<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DebugRateLimiterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        Cache::flush();
    }

    #[Test]
    public function debug_login_rate_limiter(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin', 'email' => 'admin@example.com', 'is_active' => true,
        ]);
        $adminRole = Role::create(['name' => 'admin', 'guard_name' => 'api']);
        $admin->assignRole($adminRole);

        for ($i = 0; $i < 6; $i++) {
            $resp = $this->postJson(route("api.v1.auth.login"), [
                "identifier" => $admin->email, "password" => "wrong",
            ]);
            echo "Request " . ($i+1) . ": status=" . $resp->status() . " body=" . $resp->json("message") . "\n";
        }
    }
}
