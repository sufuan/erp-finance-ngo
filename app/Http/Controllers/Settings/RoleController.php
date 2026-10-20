<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** Role administration + permission matrix (Step 2 §4.1). */
class RoleController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(): Response
    {
        return Inertia::render('Settings/Roles/Index', [
            'roles' => Role::with('permissions:id,name')->withCount('users')
                ->orderBy('name')
                ->get()
                ->map(fn (Role $r) => [
                    'id' => $r->id, 'name' => $r->name, 'label' => $r->label,
                    'description' => $r->description, 'is_system' => $r->is_system,
                    'users_count' => $r->users_count,
                    'permissions' => $r->permissions->pluck('name')->all(),
                ]),
            'permissionGroups' => Permission::orderBy('module')->orderBy('name')->get(['id', 'name', 'module', 'label'])
                ->groupBy('module')
                ->map(fn ($perms, $module) => ['module' => $module, 'permissions' => $perms->values()])
                ->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/', Rule::unique('roles', 'name')],
            'label' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);
        $role = Role::create(['name' => $validated['name'], 'label' => $validated['label'] ?? null, 'description' => $validated['description'] ?? null, 'is_system' => false]);
        $role->syncPermissions($validated['permissions'] ?? []);
        $this->audit->log($request->user()->id, 'role.created', 'roles', $role->id, null, ['name' => $role->name]);

        return back()->with('success', __('saved'));
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $validated = $request->validate([
            // System role names are referenced in code (e.g. TOTP rules) — immutable.
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($role->id), $role->is_system ? Rule::in([$role->name]) : 'regex:/^[a-z0-9-]+$/'],
            'label' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);
        $old = ['permissions' => $role->permissions->pluck('name')->all()];
        $role->update(['name' => $validated['name'], 'label' => $validated['label'] ?? null, 'description' => $validated['description'] ?? null]);
        $role->syncPermissions($validated['permissions'] ?? []);
        $this->audit->log($request->user()->id, 'role.updated', 'roles', $role->id, $old, ['permissions' => $validated['permissions'] ?? []]);

        return back()->with('success', __('saved'));
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        if ($role->is_system) {
            return back()->with('error', __('cannot_delete_system_role'));
        }
        if ($role->users()->count() > 0) {
            return back()->with('error', __('cannot_delete_role_with_users'));
        }
        $this->audit->log($request->user()->id, 'role.deleted', 'roles', $role->id, ['name' => $role->name], null);
        $role->delete();

        return back()->with('success', __('saved'));
    }
}
