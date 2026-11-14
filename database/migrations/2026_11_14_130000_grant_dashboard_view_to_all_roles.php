<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * dashboard.view becomes always-on (owner rule): every role must be able
 * to see the dashboard, so the Roles matrix no longer offers the tick and
 * RoleController force-merges the permission on save. This migration
 * brings EXISTING roles in line — deploys run `migrate --force` but NOT
 * the seeders. Idempotent (givePermissionTo never duplicates).
 *
 * Sole exception: the Director role. It is view-only by the client's own
 * spec and its landing redirect to /director depends on NOT holding
 * dashboard.view, so it is deliberately skipped here, in RoleSeeder and
 * in the RoleController merge.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::where('name', 'dashboard.view')->where('guard_name', 'web')->first();
        if ($permission !== null) {
            Role::where('guard_name', 'web')
                ->where('name', '!=', 'director')
                ->get()
                ->each(fn (Role $role) => $role->givePermissionTo($permission));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // The always-on grant is the standing product rule; rolling back
        // the code does not revoke a permission roles may rely on to land.
    }
};
