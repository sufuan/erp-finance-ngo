<?php

namespace Database\Seeders;

use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionCatalog::all() as $name => [$module, $label]) {
            Permission::updateOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['module' => $module, 'label' => $label]
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
