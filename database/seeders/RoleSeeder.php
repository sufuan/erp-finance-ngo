<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Plan Step 2 roles. Permission mapping is code (seed), not settings —
 * admins adjust it live in the Roles matrix UI afterwards.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $all = Permission::pluck('name')->all();

        $readOnlyActions = ['view', 'view-statement', 'view-schedule', 'reports', 'print', 'export', 'export-excel', 'mis', 'pomis', 'balance', 'statements', 'search'];
        $auditor = array_values(array_filter($all, function (string $name) use ($readOnlyActions) {
            return in_array(substr($name, strrpos($name, '.') + 1), $readOnlyActions, true);
        }));

        $defs = [
            'super-admin' => [
                'label' => 'Super Admin', 'description' => 'Full access to everything, including break-glass.',
                'permissions' => $all,
            ],
            'branch-manager' => [
                'label' => 'Branch Manager', 'description' => 'Runs a branch; cannot manage users/roles or use break-glass.',
                'permissions' => array_values(array_diff($all, ['settings.users-manage', 'settings.roles-manage', 'accounts.breakglass'])),
            ],
            'accountant' => [
                'label' => 'Accountant', 'description' => 'Accounting, deposits and reports for assigned branches.',
                'permissions' => array_values(array_filter($all, function (string $n) {
                    if ($n === 'accounts.breakglass') {
                        return false;
                    }

                    return str_starts_with($n, 'dashboard.') || str_starts_with($n, 'accounts.')
                        || str_starts_with($n, 'deposits.') || str_starts_with($n, 'reports.')
                        || str_starts_with($n, 'regulatory.') || str_starts_with($n, 'files.')
                        || in_array($n, ['members.view', 'loans.view', 'loans.view-schedule', 'loans.print', 'loans.reports', 'loans.classification', 'collection.view', 'collection.reports', 'collection.print'], true);
                })),
            ],
            'field-officer' => [
                'label' => 'Field Officer', 'description' => 'Field collection and member work in assigned samities/branches.',
                'permissions' => [
                    'dashboard.view',
                    'members.view', 'members.create', 'members.edit', 'members.visits', 'members.documents', 'members.photo', 'members.print', 'members.search', 'members.kyc',
                    'collection.view', 'collection.collect', 'collection.sheets', 'collection.reports', 'collection.print',
                    'loans.view', 'loans.print', 'loans.view-schedule',
                    'deposits.view', 'deposits.receive', 'deposits.view-statement', 'deposits.print',
                    'gps.view', 'gps.track', 'gps.reports',
                    'files.view', 'files.upload',
                ],
            ],
            'hr-officer' => [
                'label' => 'HR Officer', 'description' => 'Employees, attendance and payroll masters.',
                'permissions' => array_values(array_filter($all, fn (string $n) => str_starts_with($n, 'hrm.') || $n === 'dashboard.view' || in_array($n, ['files.view', 'files.upload'], true))),
            ],
            'auditor' => [
                'label' => 'Auditor', 'description' => 'Read-only access across every module.',
                'permissions' => $auditor,
            ],
        ];

        foreach ($defs as $name => $def) {
            $role = Role::updateOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['label' => $def['label'], 'description' => $def['description'], 'is_system' => true]
            );
            $role->syncPermissions($def['permissions']);
        }
    }
}
