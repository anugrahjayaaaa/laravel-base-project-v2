<?php

namespace Database\Seeders;

use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class SuperAdminSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // `#Password123` ships in this repository, so it is only a dev
        // convenience — and this is the one account a real install cannot do
        // without. Outside local/testing the operator must name the password in
        // `SEED_PASSWORD` first; a generated-and-printed one would be written to
        // whatever captured the deploy log. `db:seed --force` is the documented
        // install step, so the requirement is stated in deployment.md § 5.
        $password = '#Password123';

        if (! app()->environment('local', 'testing')) {
            $configured = env('SEED_PASSWORD');

            if (! is_string($configured) || $configured === '') {
                throw new RuntimeException('Set SEED_PASSWORD before seeding the superadmin account outside local/testing; its password must not be the one published in this repository.');
            }

            $password = $configured;
        }

        $user = User::updateOrCreate(
            ['email' => 'superadmin@admin.com'],
            [
                'name' => 'Super Admin',
                'username' => 'superadmin',
                'password' => Hash::make($password),
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // The role assignment was missing, and it only became visible once
        // Gate::before arrived in Phase 6 Group B. This user's access does not
        // come from role_has_permissions — it comes from
        // `$user->hasRole('superadmin')` in App\Providers\AuthServiceProvider.
        // Without the row here, the superadmin account is denied everything,
        // which reads as "the gate is broken" rather than "the seeder is
        // incomplete". syncRoles, not assignRole: a re-seed must not leave a
        // second role on the account.
        //
        // RoleLookup, not config('auth.defaults.guard'): Spatie resolves the
        // guard it actually keys on, and a mismatch here is the duplicate-role
        // bug RoleGuardTest exists to catch.
        $role = Role::firstOrCreate([
            'name' => SystemRole::SUPERADMIN,
            'guard_name' => RoleLookup::guard(),
        ]);

        $user->syncRoles([$role]);
    }
}
