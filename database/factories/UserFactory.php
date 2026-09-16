<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_active' => true,
            'is_locked' => false,
            'must_change_password' => false,
            'password_expires_at' => null,
            'last_activity_at' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /** Phase 3 states */

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function locked(): static
    {
        return $this->state(['is_locked' => true]);
    }

    public function mustChangePassword(): static
    {
        return $this->state(['must_change_password' => true]);
    }

    public function expiredPassword(): static
    {
        return $this->state(['password_expires_at' => now()->subDay()]);
    }

    /**
     * Use a strong password that meets the IM8 policy.
     */
    public function withStrongPassword(): static
    {
        return $this->state(['password' => Hash::make('Password123!')]);
    }
}
