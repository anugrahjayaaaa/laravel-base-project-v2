<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Fixed users user1-user10 with known password for dev/testing.
        //
        // `#Password123` is in this repository's source, so outside those two
        // environments it is not a dev convenience but a public credential on
        // ten real accounts. The seeder's own stated scope is dev/testing, and
        // this is that scope enforced rather than assumed.
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('UserSeeder creates accounts with the published password #Password123 and refuses to run outside local/testing.');
        }

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
