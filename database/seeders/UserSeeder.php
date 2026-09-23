<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Fixed users user1-user10 with known password for dev/testing
        for ($i = 1; $i <= 10; $i++) {
            User::factory()->create([
                'name' => "User {$i}",
                'username' => "User{$i}",
                'email' => "user{$i}@example.com",
                'password' => Hash::make('#Password123'),
            ]);
        }
    }
}