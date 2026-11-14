<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Director (পরিচালক) view-only dashboard — live-deploy data migration.
 *
 * Deploys run `migrate --force` but NOT the seeders, so the permission,
 * the system role and their link are created here, idempotently
 * (firstOrCreate / updateOrCreate — safe to re-run). The same entries
 * live in PermissionCatalog + RoleSeeder for fresh installs.
 *
 * Scope of grants: the Director role gets ONLY 'director.view'; the
 * Super Admin role also receives it (so IT can open the page). No other
 * role's permissions are touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::updateOrCreate(
            ['name' => 'director.view', 'guard_name' => 'web'],
            ['module' => 'dashboard', 'label' => 'Director Dashboard — View']
        );

        $director = Role::updateOrCreate(
            ['name' => 'director', 'guard_name' => 'web'],
            ['label' => 'Director', 'description' => 'View-only consolidated overview of all branches (পরিচালক).', 'is_system' => true]
        );
        $director->syncPermissions([$permission]);

        $superAdmin = Role::where('name', 'super-admin')->where('guard_name', 'web')->first();
        $superAdmin?->givePermissionTo($permission);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::where('name', 'director.view')->where('guard_name', 'web')->first();
        $director = Role::where('name', 'director')->where('guard_name', 'web')->first();
        $director?->delete();
        $permission?->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
