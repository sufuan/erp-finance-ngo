<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeded Super Admin (plan Step 2 acceptance: "seeded Super Admin logs
 * in"). The password is recorded in BUILD_LOG.md and must_change_password
 * forces a change + TOTP enrollment on first login. Re-seeding never
 * resets an existing admin's password.
 */
class AdminUserSeeder extends Seeder
{
    public const USERNAME = 'admin';

    public const INITIAL_PASSWORD = 'Tpda#Admin2026!';

    public function run(): void
    {
        $user = User::firstOrNew(['username' => self::USERNAME]);
        if (! $user->exists) {
            $user->fill([
                'name' => 'Super Admin',
                'email' => 'admin@tpda-esheba.local',
                'password' => Hash::make(self::INITIAL_PASSWORD),
                'locale' => 'bn',
                'is_active' => true,
                'must_change_password' => true,
            ])->save();
        }
        $user->syncRoles(['super-admin']);
    }
}
