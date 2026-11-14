<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\LoginAudit;
use App\Models\Samity;
use App\Models\User;
use App\Services\AuditService;
use App\Services\BranchAccessService;
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

    public function index(Request $request): Response
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
            'employees' => Employee::orderBy('name')->get(['id', 'name', 'email', 'branch_id']),
            // Samities the acting user may offer for assignment, scoped to
            // the branches they can access; the form groups them under the
            // selected branch so nobody has to know a numeric samity ID.
            'samities' => Samity::whereIn('branch_id', app(BranchAccessService::class)->accessibleIds($request->user()))
                ->orderBy('samity_code')
                ->get(['id', 'samity_code', 'name', 'branch_id'])
                ->map(fn (Samity $s) => ['id' => $s->id, 'code' => $s->samity_code, 'name' => $s->name, 'branch_id' => $s->branch_id])
                ->all(),
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
            'locale' => $validated['locale'] ?? 'en',
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
            'employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')],
            'locale' => ['nullable', Rule::in(['en', 'bn'])],
            'password' => $user ? ['nullable'] : ['required', 'string', 'min:8'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'samity_ids' => ['nullable', 'array'],
            'samity_ids.*' => ['integer', Rule::exists('samities', 'id')],
        ]);
    }

    private function syncAssignments(User $user, Request $request): void
    {
        $user->syncRoles($request->input('roles', []));

        // One user, one branch: the form's Branch dropdown is the whole
        // assignment (no selection = no branch, e.g. a super admin).
        $branchId = $request->input('branch_id');
        $user->branches()->sync($branchId ? [(int) $branchId] : []);

        // Samity assignment is a checklist in the form now: the submitted
        // ID array IS the assignment (empty = none). IDs are validated to
        // exist; the picker only offers the selected branch's samities,
        // but the server contract is the same exists-check the branch
        // dropdown uses.
        $samityIds = collect($request->input('samity_ids', []))
            ->map(fn ($v) => (int) $v)->unique()->values()->all();
        $user->samities()->sync($samityIds);
    }
}
