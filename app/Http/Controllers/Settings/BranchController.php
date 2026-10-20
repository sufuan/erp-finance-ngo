<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BranchController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Branches/Index', [
            'branches' => Branch::with('parent:id,code,name')
                ->orderBy('code')
                ->paginate(25)
                ->through(fn (Branch $b) => [
                    'id' => $b->id,
                    'code' => $b->code,
                    'name' => $b->name,
                    'type' => $b->type,
                    'parent' => $b->parent ? ['id' => $b->parent->id, 'code' => $b->parent->code, 'name' => $b->parent->name] : null,
                    'opening_date' => $b->opening_date?->toDateString(),
                    'manager_phone' => $b->manager_phone,
                    'address' => $b->address,
                    'country' => $b->country,
                    'division' => $b->division,
                    'district' => $b->district,
                    'upazila' => $b->upazila,
                    'union' => $b->union,
                    'status' => $b->status,
                ]),
            'parents' => Branch::orderBy('code')->get(['id', 'code', 'name']),
            'types' => ['Branch', 'Regional', 'Zonal', 'Head Office'],
        ]);
    }

    public function store(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $this->validateBranch($request);
        $data['created_by'] = $request->user()?->id;
        $branch = Branch::create($data);
        $audit->log($request->user()?->id, 'branch.created', 'branches', $branch->id, null, $branch->toArray());

        return back()->with('success', __('saved_successfully'));
    }

    public function update(Request $request, Branch $branch, AuditService $audit): RedirectResponse
    {
        $data = $this->validateBranch($request, $branch);
        $old = $branch->toArray();
        $data['updated_by'] = $request->user()?->id;
        $branch->update($data);
        $audit->log($request->user()?->id, 'branch.updated', 'branches', $branch->id, $old, $branch->toArray());

        return back()->with('success', __('saved_successfully'));
    }

    private function validateBranch(Request $request, ?Branch $branch = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:branches,code'.($branch ? ','.$branch->id : '')],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:Branch,Regional,Zonal,Head Office'],
            'parent_branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'opening_date' => ['nullable', 'date'],
            'country' => ['nullable', 'string', 'max:100'],
            'division' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'upazila' => ['nullable', 'string', 'max:100'],
            'union' => ['nullable', 'string', 'max:100'],
            'manager_phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'status' => ['required', 'in:Active,Inactive'],
        ]);
    }
}
