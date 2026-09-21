<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        User::factory()->count(20)->create();
        User::factory()->count(5)->inactive()->create();
        User::factory()->count(3)->locked()->create();
        User::factory()->count(4)->unverified()->create();
    }
}