<?php

namespace Tests\Feature;

use App\Actions\V1\User\CreateUserAction;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->seed(\Database\Seeders\SystemSettingSeeder::class);
    }

    public function test_records_password_history_on_creation(): void
    {
        $action = app(CreateUserAction::class);

        $user = $action->run([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'username' => 'testuser',
        ]);

        $this->assertDatabaseHas('password_histories', [
            'user_id' => $user->id,
        ]);
    }

    public function test_prevents_reuse_of_recent_passwords(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('CurrentP@ss1!'),
        ]);

        DB::table('password_histories')->insert([
            'user_id' => $user->id,
            'password' => Hash::make('PreviousP@ss1!'),
            'created_at' => now()->subDays(3),
        ]);
        DB::table('password_histories')->insert([
            'user_id' => $user->id,
            'password' => Hash::make('AnotherP@ss2!'),
            'created_at' => now()->subDays(2),
        ]);

        $response = $this->actingAs($user)->put('/password/change', [
            'current_password' => 'CurrentP@ss1!',
            'password' => 'PreviousP@ss1!',
            'password_confirmation' => 'PreviousP@ss1!',
        ]);

        $response->assertSessionHasErrors('password');
    }

    public function test_allows_reuse_after_n_changes(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('OriginalP@ss1!'),
        ]);

        $currentPassword = 'OriginalP@ss1!';
        for ($i = 1; $i <= 5; $i++) {
            $newPassword = "NewP@ss{$i}!";
            $this->actingAs($user)->put('/password/change', [
                'current_password' => $currentPassword,
                'password' => $newPassword,
                'password_confirmation' => $newPassword,
            ]);
            $currentPassword = $newPassword;
            $user->refresh();
        }

        $response = $this->actingAs($user)->put('/password/change', [
            'current_password' => 'NewP@ss5!',
            'password' => 'OriginalP@ss1!',
            'password_confirmation' => 'OriginalP@ss1!',
        ]);

        $response->assertSessionDoesntHaveErrors('password');
    }

    public function test_ignores_history_when_disabled(): void
    {
        SystemSetting::set('password_history_enabled', 'false');

        $user = User::factory()->create([
            'password' => Hash::make('CurrentP@ss1!'),
        ]);

        DB::table('password_histories')->insert([
            'user_id' => $user->id,
            'password' => Hash::make('OldP@ssword1!'),
            'created_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($user)->put('/password/change', [
            'current_password' => 'CurrentP@ss1!',
            'password' => 'OldP@ssword1!',
            'password_confirmation' => 'OldP@ssword1!',
        ]);

        $response->assertSessionDoesntHaveErrors('password');
    }

    public function test_history_count_prunes_old_entries(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('StartP@ss1!'),
        ]);

        // Insert 7 history entries (limit is 5)
        for ($i = 1; $i <= 7; $i++) {
            DB::table('password_histories')->insert([
                'user_id' => $user->id,
                'password' => Hash::make("OldP@ss{$i}!"),
                'created_at' => now()->subDays(10 - $i),
            ]);
        }

        // Change password to trigger pruning
        $this->actingAs($user)->put('/password/change', [
            'current_password' => 'StartP@ss1!',
            'password' => 'BrandNewP@ss1!',
            'password_confirmation' => 'BrandNewP@ss1!',
        ]);

        $count = DB::table('password_histories')
            ->where('user_id', $user->id)
            ->count();

        $this->assertEquals(5, $count, 'History should be pruned to the configured limit.');
    }
}
