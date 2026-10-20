<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;

/** Read-only permission catalog, grouped by module (Step 2). */
class PermissionController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Permissions/Index', [
            'permissionGroups' => Permission::orderBy('module')->orderBy('name')->get(['id', 'name', 'module', 'label'])
                ->groupBy('module')
                ->map(fn ($perms, $module) => ['module' => $module, 'permissions' => $perms->values()])
                ->values(),
        ]);
    }
}
