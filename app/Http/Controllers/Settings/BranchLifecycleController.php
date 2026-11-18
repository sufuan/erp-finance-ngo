<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchManagerChange;
use App\Models\BusinessDay;
use App\Models\Employee;
use App\Models\Samity;
use App\Services\AuditService;
use App\Services\BusinessDayService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Branch-level lifecycle (client round 2026-11-18, replacing the old
 * samity-level manager change & merge):
 *
 *  - Branch Manager Change: branches.manager_id (→ employees) with a
 *    history table, mirroring the samity change features.
 *  - Branch Merge: every samity of the source branch moves into the
 *    target branch; members / deposit accounts / loans / proposals
 *    follow through their samity (numbers never change). Posted history
 *    (vouchers, transactions, collection batches, business days) keeps
 *    its original branch_id — history is never rewritten. The source
 *    branch is closed (status Inactive, open business day closed).
 *  - Branch Split: ticked samities move out into a brand-new branch
 *    (next free numeric code); the source keeps the rest.
 */
class BranchLifecycleController extends Controller
{
    private function businessDate(Request $request): string
    {
        $date = $request->attributes->get('business_date');

        return $date ? Carbon::parse($date)->toDateString() : now()->toDateString();
    }

    /** Active branches with manager + live counts, for the three forms. */
    private function branchOptions(): array
    {
        return Branch::where('status', 'Active')->with('manager:id,name')->orderBy('code')->get()
            ->map(fn (Branch $b) => [
                'id' => $b->id,
                'name' => "{$b->code} — {$b->name}",
                'manager_id' => $b->manager_id,
                'manager_name' => $b->manager?->name,
                'samity_count' => DB::table('samities')->where('branch_id', $b->id)->where('status', 'Active')->count(),
                'member_count' => DB::table('members')->where('branch_id', $b->id)->count(),
            ])->all();
    }

    private function employeeOptions(): array
    {
        return Employee::where('status', 'Active')->orderBy('name')->get(['id', 'employee_code', 'name'])
            ->map(fn ($e) => ['id' => $e->id, 'name' => "{$e->employee_code} — {$e->name}"])->all();
    }

    // --- Branch Manager Change -------------------------------------------

    public function managerChangeForm(Request $request): Response
    {
        $history = BranchManagerChange::with('branch')->orderByDesc('id')->limit(100)->get()->map(fn ($row) => [
            ...$row->toArray(),
            'branch' => $row->branch?->only(['id', 'code', 'name']),
            'previous_label' => $this->employeeLabel($row->previous_value),
            'new_label' => $this->employeeLabel($row->new_value),
        ]);

        return Inertia::render('Settings/Branches/ManagerChange', [
            'branches' => $this->branchOptions(),
            'employees' => $this->employeeOptions(),
            'history' => $history,
            'businessDate' => $this->businessDate($request),
        ]);
    }

    private function employeeLabel(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Employee::find((int) $value)?->name ?? (string) $value;
    }

    public function applyManagerChange(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'new_value' => ['required', 'integer', 'exists:employees,id'],
            'change_date' => ['required', 'date'],
            'effective_from' => ['required', 'date'],
            'comments' => ['nullable', 'string'],
        ]);

        $branch = Branch::findOrFail($data['branch_id']);
        $previous = $branch->manager_id;
        BranchManagerChange::create([
            'branch_id' => $branch->id,
            'change_date' => $data['change_date'],
            'effective_from' => $data['effective_from'],
            'previous_value' => $previous !== null ? (string) $previous : null,
            'new_value' => (string) $data['new_value'],
            'comments' => $data['comments'] ?? null,
            'created_by' => $request->user()->id,
        ]);
        $branch->update(['manager_id' => $data['new_value'], 'updated_by' => $request->user()->id]);
        $audit->log($request->user()->id, 'branch.manager_changed', 'branches', $branch->id,
            ['manager_id' => $previous], ['manager_id' => $data['new_value']]);

        return back()->with('success', __('saved'));
    }

    // --- Shared ownership move (merge & split) ----------------------------

    /**
     * Move the given samities (and everything that hangs off them) from
     * one branch to another. Live ownership only — posted history rows
     * (vouchers, transactions, batches, business days) are never touched.
     *
     * @param  array<int, int>  $samityIds
     * @return array{samities:int, members:int} counts moved
     */
    private function moveSamities(array $samityIds, int $toBranchId, Request $request, AuditService $audit): array
    {
        if (! $samityIds) {
            return ['samities' => 0, 'members' => 0];
        }
        $memberIds = DB::table('members')->whereIn('samity_id', $samityIds)->pluck('id')->all();

        foreach ($samityIds as $samityId) {
            $from = DB::table('samities')->where('id', $samityId)->value('branch_id');
            DB::table('samities')->where('id', $samityId)
                ->update(['branch_id' => $toBranchId, 'updated_by' => $request->user()->id, 'updated_at' => now()]);
            $audit->log($request->user()->id, 'samity.branch_moved', 'samities', (int) $samityId,
                ['branch_id' => $from], ['branch_id' => $toBranchId]);
        }
        if ($memberIds) {
            DB::table('members')->whereIn('id', $memberIds)
                ->update(['branch_id' => $toBranchId, 'updated_by' => $request->user()->id, 'updated_at' => now()]);
            DB::table('deposit_accounts')->whereIn('member_id', $memberIds)->update(['branch_id' => $toBranchId, 'updated_at' => now()]);
            DB::table('loan_accounts')->whereIn('member_id', $memberIds)->update(['branch_id' => $toBranchId, 'updated_at' => now()]);
            DB::table('loan_proposals')->whereIn('member_id', $memberIds)->update(['branch_id' => $toBranchId, 'updated_at' => now()]);
        }

        return ['samities' => count($samityIds), 'members' => count($memberIds)];
    }

    /** Combined live totals across the given branches (merge invariant). */
    private function combinedTotals(array $branchIds): array
    {
        $money = app(\App\Services\LoanMoneyService::class);
        $outstanding = \Brick\Math\BigDecimal::zero();
        foreach (\App\Models\LoanAccount::whereIn('branch_id', $branchIds)->where('status', 'Active')->get() as $loan) {
            $outstanding = $outstanding->plus($money->outstanding($loan)['total']);
        }

        return [
            'members' => (int) DB::table('members')->whereIn('branch_id', $branchIds)->count(),
            'savings' => (string) DB::table('deposit_accounts')->whereIn('branch_id', $branchIds)->sum('current_balance'),
            'outstanding' => (string) $outstanding,
        ];
    }

    // --- Branch Merge ------------------------------------------------------

    public function mergeForm(Request $request): Response
    {
        return Inertia::render('Settings/Branches/Merge', [
            'branches' => $this->branchOptions(),
            'businessDate' => $this->businessDate($request),
        ]);
    }

    public function merge(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'source_branch_id' => ['required', 'integer', 'exists:branches,id', 'different:target_branch_id'],
            'target_branch_id' => ['required', 'integer', 'exists:branches,id'],
        ]);
        $source = Branch::findOrFail($data['source_branch_id']);
        $target = Branch::findOrFail($data['target_branch_id']);
        if ($source->status !== 'Active' || $target->status !== 'Active') {
            throw ValidationException::withMessages(['source_branch_id' => __('branch_merge_active_only')]);
        }

        DB::transaction(function () use ($request, $source, $target, $audit) {
            $before = $this->combinedTotals([$source->id, $target->id]);

            $samityIds = Samity::withoutGlobalScopes()->where('branch_id', $source->id)->pluck('id')
                ->map(fn ($id) => (int) $id)->all();
            $moved = $this->moveSamities($samityIds, $target->id, $request, $audit);

            // Any member still stamped with the source branch (e.g. its
            // samity was closed earlier) follows too — the branch closes.
            $strayMemberIds = DB::table('members')->where('branch_id', $source->id)->pluck('id')->all();
            if ($strayMemberIds) {
                DB::table('members')->whereIn('id', $strayMemberIds)
                    ->update(['branch_id' => $target->id, 'updated_by' => $request->user()->id, 'updated_at' => now()]);
                DB::table('deposit_accounts')->whereIn('member_id', $strayMemberIds)->update(['branch_id' => $target->id, 'updated_at' => now()]);
                DB::table('loan_accounts')->whereIn('member_id', $strayMemberIds)->update(['branch_id' => $target->id, 'updated_at' => now()]);
                DB::table('loan_proposals')->whereIn('member_id', $strayMemberIds)->update(['branch_id' => $target->id, 'updated_at' => now()]);
            }

            // Staff of the closing branch transfer with the operation;
            // child branches are re-parented to the target. Login access
            // grants (branch_user) and samity ticks are NOT moved — those
            // are admin assignment decisions.
            $employeesMoved = DB::table('employees')->where('branch_id', $source->id)
                ->update(['branch_id' => $target->id, 'updated_at' => now()]);
            DB::table('branches')->where('parent_branch_id', $source->id)
                ->update(['parent_branch_id' => $target->id, 'updated_at' => now()]);

            // Close the source branch: its open business day closes (no
            // next day opens) and the branch leaves every active picker.
            BusinessDay::where('branch_id', $source->id)->where('is_closed', false)
                ->update(['is_closed' => true, 'closed_by' => $request->user()->id, 'closed_at' => now(), 'updated_by' => $request->user()->id]);
            $source->update(['status' => 'Inactive', 'updated_by' => $request->user()->id]);

            $after = $this->combinedTotals([$source->id, $target->id]);
            $audit->log($request->user()->id, 'branch.merged', 'branches', $source->id,
                ['status' => 'Active', 'totals' => $before],
                ['status' => 'Inactive', 'into' => $target->id, 'samities_moved' => $moved['samities'],
                    'members_moved' => $moved['members'], 'employees_moved' => $employeesMoved, 'totals' => $after]);
        });

        return back()->with('success', __('saved'));
    }

    // --- Branch Split ------------------------------------------------------

    /** Next free numeric branch code (codes are numeric strings: 101, 102…). */
    private function nextBranchCode(): string
    {
        $max = Branch::pluck('code')->map(fn ($c) => (int) $c)->max() ?? 100;

        return (string) ($max + 1);
    }

    public function splitForm(Request $request): Response
    {
        $samities = Samity::withoutGlobalScopes()->orderBy('samity_code')->get(['id', 'samity_code', 'name', 'branch_id'])
            ->map(fn (Samity $s) => [
                'id' => $s->id, 'name' => "{$s->samity_code} — {$s->name}", 'branch_id' => $s->branch_id,
                'member_count' => DB::table('members')->where('samity_id', $s->id)->count(),
            ])->all();

        return Inertia::render('Settings/Branches/Split', [
            'branches' => $this->branchOptions(),
            'samities' => $samities,
            'nextCode' => $this->nextBranchCode(),
            'businessDate' => $this->businessDate($request),
        ]);
    }

    public function split(Request $request, AuditService $audit, BusinessDayService $businessDays): RedirectResponse
    {
        $data = $request->validate([
            'source_branch_id' => ['required', 'integer', 'exists:branches,id'],
            'samity_ids' => ['required', 'array', 'min:1'],
            'samity_ids.*' => ['integer', 'exists:samities,id'],
            'new_branch_name' => ['required', 'string', 'max:255'],
        ]);
        $source = Branch::findOrFail($data['source_branch_id']);
        if ($source->status !== 'Active') {
            throw ValidationException::withMessages(['source_branch_id' => __('branch_merge_active_only')]);
        }
        $samityIds = collect($data['samity_ids'])->map(fn ($id) => (int) $id)->unique()->all();
        $owned = Samity::withoutGlobalScopes()->whereIn('id', $samityIds)
            ->where('branch_id', $source->id)->pluck('id')->count();
        if ($owned !== count($samityIds)) {
            throw ValidationException::withMessages(['samity_ids' => __('branch_split_samity_not_in_branch')]);
        }

        DB::transaction(function () use ($request, $data, $source, $samityIds, $audit, $businessDays) {
            $new = Branch::create([
                'code' => $this->nextBranchCode(),
                'name' => $data['new_branch_name'],
                'type' => 'Branch',
                'parent_branch_id' => null,
                'opening_date' => $businessDays->currentDate($source->id),
                'country' => $source->country, 'division' => $source->division,
                'district' => $source->district, 'upazila' => $source->upazila, 'union' => $source->union,
                'division_id' => $source->division_id, 'district_id' => $source->district_id,
                'upazila_id' => $source->upazila_id, 'union_id' => $source->union_id,
                'address' => $source->address,
                'status' => 'Active',
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);

            $moved = $this->moveSamities($samityIds, $new->id, $request, $audit);

            // A new branch opens its first business day at the real
            // current date — the same lazy open a normal branch gets.
            $businessDays->current($new->id);

            $audit->log($request->user()->id, 'branch.split', 'branches', $new->id, null,
                ['from' => $source->id, 'code' => $new->code, 'samities_moved' => $moved['samities'], 'members_moved' => $moved['members']]);
        });

        return back()->with('success', __('saved'));
    }
}
