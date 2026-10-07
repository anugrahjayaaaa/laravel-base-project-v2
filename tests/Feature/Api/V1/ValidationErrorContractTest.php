<?php

namespace Tests\Feature\Api\V1;

use App\Models\RoleLookup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The 422 error contract on every Form Request.
 *
 * Only the four Auth requests used FormatsApiErrors individually; the other
 * seventeen fell through to Laravel's default `{message, errors}`. All of them
 * now inherit the trait from BaseFormRequest, so each validation failure returns
 * `{message, errors, code, meta}`.
 *
 * A representative sample across domains, not all 21 endpoints — the Arch suite
 * pins that every Request extends the base, which is what makes this hold
 * everywhere.
 */
class ValidationErrorContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $adminRole = Role::where('name', 'admin')
            ->where('guard_name', RoleLookup::guard())
            ->firstOrFail();
        $admin = User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'is_active' => true,
        ]);
        $admin->assignRole($adminRole);

        Sanctum::actingAs($admin, ['*']);
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function failingRequests(): array
    {
        return [
            'User' => ['postJson', '/api/v1/users', ['email' => 'not-an-email']],
            'Role' => ['postJson', '/api/v1/roles', ['name' => '']],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
        #[DataProvider('failingRequests')]
    public function it_returns_the_shared_error_contract(string $method, string $uri, array $payload): void
    {
        $response = $this->{$method}($uri, $payload);

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'errors', 'code', 'meta'])
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['meta' => ['request_id', 'timestamp']]);

        // The keys Laravel already produced must survive, or clients that read
        // them break the moment the contract is unified.
        $this->assertArrayHasKey('message', $response->json());
        $this->assertArrayHasKey('errors', $response->json());
    }
}
