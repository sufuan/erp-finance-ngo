<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            BranchSeeder::class,
            PermissionSeeder::class,
            RoleSeeder::class,
            AdminUserSeeder::class,
            OrganizationProfileSeeder::class,
            SettingsSeeder::class,
            ChartOfAccountsSeeder::class,
            GlMappingSeeder::class,
            VoucherTypeSeeder::class,
            FiscalYearSeeder::class,
            GeoSeeder::class,
        ]);
    }
}
