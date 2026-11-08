<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Database\Seeders\MemberDemoSeeder;
use Database\Seeders\SuperAdminSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * The seeded credentials are published in this repository's source (Phase 18
 * pentest finding: every demo account and the superadmin shared `#Password123`,
 * and `db:seed --force` is the documented install step).
 *
 * The suite itself runs in `testing`, which is the case the seeders are allowed
 * to serve, so each test that wants the production behaviour has to say so.
 */
class SeedCredentialTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        putenv('SEED_PASSWORD');
        unset($_ENV['SEED_PASSWORD'], $_SERVER['SEED_PASSWORD']);

        parent::tearDown();
    }

    /**
     * @param  class-string<Seeder>  $seeder
     */
    private function assertRefuses(string $seeder): void
    {
        try {
            (new $seeder())->run();
            $this->fail("{$seeder} seeded a published password in production");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('password', $e->getMessage());
        }
    }

    public function test_the_superadmin_seeder_refuses_the_published_password_outside_local_and_testing(): void
    {
        $this->app['env'] = 'production';

        $this->assertRefuses(SuperAdminSeeder::class);

        $this->assertNull(User::where('email', 'superadmin@admin.com')->first(), 'a refused seed writes no admin account');
    }

    public function test_the_demo_account_seeders_refuse_to_run_outside_local_and_testing(): void
    {
        $this->app['env'] = 'production';

        $this->assertRefuses(UserSeeder::class);
        $this->assertRefuses(MemberDemoSeeder::class);

        $this->assertSame(0, User::query()->count(), 'no demo accounts were written');
    }

    public function test_the_superadmin_seeder_uses_the_password_the_operator_named(): void
    {
        $this->app['env'] = 'production';

        putenv('SEED_PASSWORD=Only-The-Operator-Knows-It');
        $_ENV['SEED_PASSWORD'] = 'Only-The-Operator-Knows-It';

        (new SuperAdminSeeder())->run();

        $admin = User::where('email', 'superadmin@admin.com')->firstOrFail();

        $this->assertTrue(Hash::check('Only-The-Operator-Knows-It', $admin->password));
        $this->assertFalse(Hash::check('#Password123', $admin->password));
    }

    public function test_local_and_testing_keep_the_known_dev_password(): void
    {
        (new SuperAdminSeeder())->run();

        $this->assertTrue(Hash::check('#Password123', User::where('email', 'superadmin@admin.com')->firstOrFail()->password));
    }

    public function test_the_demo_accounts_still_seed_for_a_local_developer(): void
    {
        $this->app['env'] = 'local';

        (new UserSeeder())->run();

        $this->assertSame(10, User::where('email', 'like', 'user%@example.com')->count());
    }
}
