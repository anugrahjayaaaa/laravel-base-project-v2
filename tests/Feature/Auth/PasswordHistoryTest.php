<?php

namespace Tests\Feature\Auth;

use App\Actions\V1\User\UserCreateAction;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use App\Http\Middleware\VerifyCsrfToken;
use Database\Seeders\SystemSettingSeeder;

class PasswordHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->seed(SystemSettingSeeder::class);
    }

    public function test_generated_temporary_password_is_not_recorded(): void
    {
        $action = app(UserCreateAction::class);

        $user = $action->run([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'username' => 'testuser',
        ]);

        // The user never chose this password, so reuse history has nothing to
        // protect — recording it would only block that string from being picked
        // later for no reason.
        $this->assertDatabaseMissing('password_histories', [
            'user_id' => $user->id,
        ]);
    }

    public function test_self_chosen_password_is_recorded(): void
    {
        $action = app(UserCreateAction::class);

        $user = $action->run([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'username' => 'testuser',
        ], 'ChosenP@ss1!');

        $this->assertDatabaseHas('password_histories', [
            'user_id' => $user->id,
        ]);
    }

    public function test_password_change_revokes_all_web_sessions(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('CurrentP@ss1!'),
            'remember_token' => 'remember-me',
        ]);

        DB::table('sessions')->insert([
            'id' => 'session-for-password-change',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test-agent',
            'payload' => 'test-payload',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($user)->put('/password/change', [
            'current_password' => 'CurrentP@ss1!',
            'password' => 'ChangedP@ss1!',
            'password_confirmation' => 'ChangedP@ss1!',
        ])->assertRedirect();

        $this->assertDatabaseMissing('sessions', [
            'id' => 'session-for-password-change',
        ]);
        $this->assertNull($user->fresh()->remember_token);
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
