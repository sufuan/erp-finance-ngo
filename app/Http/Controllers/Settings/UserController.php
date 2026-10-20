<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\LoginAudit;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * User administration (Step 2): CRUD + roles + branch/samity assignment.
 * Every mutation is written to the append-only audit log.
 */
class UserController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(): Response
    {
        $users = User::with(['roles:id,name,label', 'branches:id,code,name'])
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'username' => $u->username,
                'email' => $u->email,
                'employee_id' => $u->employee_id,
                'locale' => $u->locale,
                'is_active' => $u->is_active,
                'must_change_password' => $u->must_change_password,
                'last_login_at' => $u->last_login_at?->toDateTimeString(),
                'roles' => $u->roles->pluck('name')->all(),
                'branches' => $u->branches->map(fn ($b) => ['id' => $b->id, 'include_children' => (bool) $b->pivot->include_children])->all(),
                'samity_ids' => DB::table('samity_user')->where('user_id', $u->id)->pluck('samity_id')->all(),
            ]);

        return Inertia::render('Settings/Users/Index', [
            'users' => $users,
            'roles' => Role::orderBy('name')->get(['id', 'name', 'label']),
            'branches' => Branch::orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateUser($request);
        $user = User::create([
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $validated['email'] ?? null,
            'employee_id' => $validated['employee_id'] ?? null,
            'locale' => $validated['locale'] ?? 'bn',
            'password' => Hash::make($validated['password']),
            'must_change_password' => true,
            'is_active' => true,
        ]);
        $this->syncAssignments($user, $request);
        $this->audit->log($request->user()->id, 'user.created', 'users', $user->id, null, ['username' => $user->username, 'roles' => $validated['roles'] ?? []]);

        return back()->with('success', __('saved'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $this->validateUser($request, $user);

        // A user must never lock themselves out of user administration.
        if ($user->id === $request->user()->id) {
            $grants = Role::whereIn('name', $validated['roles'] ?? [])->with('permissions')->get()
                ->flatMap(fn (Role $r) => $r->permissions->pluck('name'))->unique();
            if (! $grants->contains('settings.users-manage')) {
                return back()->with('error', __('cannot_remove_own_users_manage'));
            }
        }

        $old = ['name' => $user->name, 'username' => $user->username, 'roles' => $user->roles->pluck('name')->all()];
        $user->update([
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $validated['email'] ?? null,
            'employee_id' => $validated['employee_id'] ?? null,
            'locale' => $validated['locale'] ?? $user->locale,
        ]);
        $this->syncAssignments($user, $request);
        $this->audit->log($request->user()->id, 'user.updated', 'users', $user->id, $old, ['name' => $user->name, 'username' => $user->username, 'roles' => $validated['roles'] ?? []]);

        return back()->with('success', __('saved'));
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $temp = Str::password(12);
        $user->forceFill(['password' => Hash::make($temp), 'must_change_password' => true])->save();
        $this->audit->log($request->user()->id, 'user.password_reset', 'users', $user->id);

        // Shown once to the admin so they can hand it to the user.
        return back()->with('success', __('password_reset_done'))->with('temp_password', $temp)->with('temp_username', $user->username);
    }

    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', __('cannot_deactivate_self'));
        }
        if ($user->is_active && $user->hasRole('super-admin')) {
            $activeAdmins = User::role('super-admin')->where('is_active', true)->count();
            if ($activeAdmins <= 1) {
                return back()->with('error', __('cannot_deactivate_last_super_admin'));
            }
        }
        $user->update(['is_active' => ! $user->is_active]);
        $this->audit->log($request->user()->id, $user->is_active ? 'user.activated' : 'user.deactivated', 'users', $user->id);

        return back()->with('success', __('saved'));
    }

    public function loginHistory(User $user): Response
    {
        return Inertia::render('Settings/Users/LoginHistory', [
            'targetUser' => ['id' => $user->id, 'name' => $user->name, 'username' => $user->username],
            'history' => LoginAudit::where('user_id', $user->id)->latest()->limit(50)
                ->get(['id', 'ip', 'user_agent', 'success', 'created_at']),
        ]);
    }

    private function validateUser(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', Rule::unique('users', 'username')->ignore($user?->id)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'employee_id' => ['nullable', 'integer'],
            'locale' => ['nullable', Rule::in(['en', 'bn'])],
            'password' => $user ? ['nullable'] : ['required', 'string', 'min:8'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', Rule::exists('branches', 'id')],
            'branch_children' => ['nullable', 'array'],
            'branch_children.*' => ['integer'],
            'samity_ids' => ['nullable', 'string'],
        ]);
    }

    private function syncAssignments(User $user, Request $request): void
    {
        $user->syncRoles($request->input('roles', []));

        $children = collect($request->input('branch_children', []))->map(fn ($id) => (int) $id);
        $payload = [];
        foreach ($request->input('branch_ids', []) as $branchId) {
            $payload[(int) $branchId] = ['include_children' => $children->contains((int) $branchId)];
        }
        $user->branches()->sync($payload);

        $samityIds = collect(explode(',', (string) $request->input('samity_ids', '')))
            ->map(fn ($v) => (int) trim($v))->filter()->unique()->values();
        DB::table('samity_user')->where('user_id', $user->id)->delete();
        foreach ($samityIds as $samityId) {
            DB::table('samity_user')->insert(['user_id' => $user->id, 'samity_id' => $samityId, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
