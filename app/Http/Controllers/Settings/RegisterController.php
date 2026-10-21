<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Director;
use App\Models\Employee;
use App\Models\EmployeeType;
use App\Services\AuditService;
use App\Services\IdentityCheckService;
use App\Services\NumberingService;
use App\Services\UploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 6 — employee & director registers. One config-driven controller:
 * personal + bank sections, photo upload, encrypted NID/passport/TIN with
 * cross-register duplicate checks (employees + directors + members).
 */
class RegisterController extends Controller
{
    private const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    private const MARITAL = ['Single', 'Married', 'Widowed', 'Divorced'];

    private const GENDERS = ['Male', 'Female', 'Third Gender'];

    private const RELIGIONS = ['Islam', 'Hinduism', 'Buddhism', 'Christianity', 'Other'];

    private function entities(): array
    {
        $personal = [
            ['key' => 'name', 'type' => 'text', 'required' => true, 'section' => 'personal'],
            ['key' => 'father_name', 'type' => 'text', 'section' => 'personal'],
            ['key' => 'mother_name', 'type' => 'text', 'section' => 'personal'],
            ['key' => 'gender', 'type' => 'select', 'required' => true, 'section' => 'personal', 'options' => 'genders'],
            ['key' => 'dob', 'type' => 'date', 'label' => 'date_of_birth', 'section' => 'personal'],
            ['key' => 'national_id', 'type' => 'text', 'section' => 'personal'],
            ['key' => 'passport_no', 'type' => 'text', 'section' => 'personal'],
            ['key' => 'tin', 'type' => 'text', 'section' => 'personal'],
            ['key' => 'religion', 'type' => 'select', 'section' => 'personal', 'options' => 'religions'],
            ['key' => 'blood_group', 'type' => 'select', 'section' => 'personal', 'options' => 'blood_groups'],
            ['key' => 'education', 'type' => 'text', 'section' => 'personal'],
            ['key' => 'marital_status', 'type' => 'select', 'section' => 'personal', 'options' => 'marital'],
            ['key' => 'mobile', 'type' => 'text', 'required' => true, 'section' => 'personal'],
            ['key' => 'emergency_mobile', 'type' => 'text', 'section' => 'personal'],
            ['key' => 'email', 'type' => 'email', 'section' => 'personal'],
            ['key' => 'present_address', 'type' => 'textarea', 'section' => 'personal'],
            ['key' => 'permanent_address', 'type' => 'textarea', 'section' => 'personal'],
        ];
        $bank = [
            ['key' => 'bank_name', 'type' => 'text', 'section' => 'bank'],
            ['key' => 'bank_account_number', 'type' => 'text', 'section' => 'bank'],
            ['key' => 'bank_account_name', 'type' => 'text', 'section' => 'bank'],
            ['key' => 'bank_branch_name', 'type' => 'text', 'section' => 'bank'],
            ['key' => 'branch_routing_number', 'type' => 'text', 'section' => 'bank'],
            ['key' => 'bank_comments', 'type' => 'textarea', 'section' => 'bank'],
        ];

        return [
            'employees' => [
                'model' => Employee::class, 'label' => 'employees', 'folder' => 'employees',
                'table' => 'employees',
                'fields' => [
                    ['key' => 'branch_id', 'type' => 'select', 'required' => true, 'section' => 'personal', 'options' => 'branches', 'label' => 'branch'],
                    ...$personal,
                    ['key' => 'joining_date', 'type' => 'date', 'required' => true, 'section' => 'personal'],
                    ['key' => 'department_id', 'type' => 'select', 'section' => 'personal', 'options' => 'departments', 'label' => 'department'],
                    ['key' => 'designation_id', 'type' => 'select', 'section' => 'personal', 'options' => 'designations', 'label' => 'designation'],
                    ['key' => 'employee_type_id', 'type' => 'select', 'section' => 'personal', 'options' => 'employee_types', 'label' => 'employee_type'],
                    ['key' => 'manager_id', 'type' => 'select', 'section' => 'personal', 'options' => 'employees', 'label' => 'manager'],
                    ...$bank,
                    ['key' => 'status', 'type' => 'select', 'section' => 'personal', 'options' => 'statuses', 'label' => 'status'],
                ],
            ],
            'directors' => [
                'model' => Director::class, 'label' => 'directors', 'folder' => 'directors',
                'table' => 'directors',
                'fields' => [
                    ...$personal,
                    ...$bank,
                    ['key' => 'director_status', 'type' => 'select', 'section' => 'personal', 'options' => 'statuses', 'label' => 'director_status'],
                ],
            ],
        ];
    }

    private function entity(string $entity): array
    {
        $entities = $this->entities();
        abort_unless(isset($entities[$entity]), 404);

        return $entities[$entity];
    }

    public function index(Request $request, string $entity): Response
    {
        $cfg = $this->entity($entity);
        $model = $cfg['model'];
        $search = trim((string) $request->query('search', ''));

        $query = $model::query();
        if ($entity === 'employees') {
            $query->with(['branch', 'department', 'designation']);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search, $entity) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%")
                    ->orWhere('nid_hash', IdentityCheckService::nidHash($search));
                if ($entity === 'employees') {
                    $q->orWhere('employee_code', 'like', "%{$search}%");
                }
            });
        }

        $items = $query->orderBy('name')->paginate(25)->withQueryString()->through(function ($item) {
            $row = $item->toArray();
            $row['photo_url'] = $item->photo_path ? '/files/'.$item->photo_path : null;
            if ($item instanceof Employee) {
                $row['branch'] = $item->branch?->only(['id', 'code', 'name']);
                $row['department'] = $item->department?->only(['id', 'name']);
                $row['designation'] = $item->designation?->only(['id', 'name']);
            }

            return $row;
        });

        $fields = collect($cfg['fields'])->map(function ($field) {
            $field['options'] = $this->options($field['options'] ?? null);
            $field['label'] = $field['label'] ?? $field['key'];
            $field['required'] = $field['required'] ?? false;

            return $field;
        })->all();

        return Inertia::render('Settings/Registers/Index', [
            'entity' => $entity,
            'label' => $cfg['label'],
            'items' => $items,
            'fields' => $fields,
            'search' => $search,
        ]);
    }

    public function store(Request $request, string $entity, IdentityCheckService $identity, UploadService $uploads, AuditService $audit, NumberingService $numbering): RedirectResponse
    {
        $cfg = $this->entity($entity);
        $data = $this->validateData($request, $cfg, $identity);
        if ($request->hasFile('photo')) {
            $data['photo_path'] = $uploads->store($request->file('photo'), 'photo', $cfg['folder']);
        }
        if ($entity === 'employees') {
            // Employee codes are one global 000031-style sequence; the
            // head-office branch row scopes it inside NumberingService.
            $head = Branch::where('type', 'Head Office')->first() ?? Branch::orderBy('id')->first();
            $data['employee_code'] = str_pad((string) $numbering->next($head->id, 'employee'), 6, '0', STR_PAD_LEFT);
        }
        $data['created_by'] = $request->user()->id;
        $data['updated_by'] = $request->user()->id;
        $model = $cfg['model'];
        $item = $model::create($data);
        $audit->log($request->user()->id, $entity.'.created', $cfg['table'], $item->id, null, ['name' => $item->name]);

        return back()->with('success', __('saved'));
    }

    public function update(Request $request, string $entity, string $id, IdentityCheckService $identity, UploadService $uploads, AuditService $audit): RedirectResponse
    {
        $cfg = $this->entity($entity);
        $model = $cfg['model'];
        $item = $model::findOrFail($id);
        $data = $this->validateData($request, $cfg, $identity, $item);
        if ($request->hasFile('photo')) {
            $data['photo_path'] = $uploads->store($request->file('photo'), 'photo', $cfg['folder']);
        }
        $data['updated_by'] = $request->user()->id;
        $item->update($data);
        $audit->log($request->user()->id, $entity.'.updated', $cfg['table'], $item->id, null, ['name' => $item->name]);

        return back()->with('success', __('saved'));
    }

    private function options(?string $source): ?array
    {
        return match ($source) {
            'branches' => Branch::orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn ($b) => ['id' => $b->id, 'name' => "{$b->code} — {$b->name}"])->all(),
            'departments' => Department::with('branch')->orderBy('name')->get()
                ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name.' ('.($d->branch?->code ?? '').')'])->all(),
            'designations' => Designation::orderBy('name')->get(['id', 'name'])->all(),
            'employee_types' => EmployeeType::orderBy('name')->get(['id', 'name'])->all(),
            'employees' => Employee::orderBy('name')->get(['id', 'employee_code', 'name'])
                ->map(fn ($e) => ['id' => $e->id, 'name' => "{$e->employee_code} — {$e->name}"])->all(),
            'genders' => collect(self::GENDERS)->map(fn ($g) => ['id' => $g, 'name' => $g, 'label_key' => 'gender_'.strtolower(str_replace(' ', '_', $g))])->all(),
            'religions' => collect(self::RELIGIONS)->map(fn ($r) => ['id' => $r, 'name' => $r])->all(),
            'blood_groups' => collect(self::BLOOD_GROUPS)->map(fn ($b) => ['id' => $b, 'name' => $b])->all(),
            'marital' => collect(self::MARITAL)->map(fn ($m) => ['id' => $m, 'name' => $m, 'label_key' => 'marital_'.strtolower($m)])->all(),
            'statuses' => [
                ['id' => 'Active', 'name' => 'Active', 'label_key' => 'active'],
                ['id' => 'Inactive', 'name' => 'Inactive', 'label_key' => 'inactive'],
            ],
            default => null,
        };
    }

    private function validateData(Request $request, array $cfg, IdentityCheckService $identity, $item = null): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'father_name' => ['nullable', 'string', 'max:255'],
            'mother_name' => ['nullable', 'string', 'max:255'],
            'gender' => ['required', 'in:'.implode(',', self::GENDERS)],
            'present_address' => ['nullable', 'string'],
            'permanent_address' => ['nullable', 'string'],
            'dob' => ['nullable', 'date'],
            'national_id' => ['nullable', 'regex:/^\d{10}$|^\d{13}$|^\d{17}$/'],
            'passport_no' => ['nullable', 'string', 'max:50'],
            'tin' => ['nullable', 'string', 'max:50'],
            'religion' => ['nullable', 'string', 'max:50'],
            'blood_group' => ['nullable', 'in:'.implode(',', self::BLOOD_GROUPS)],
            'education' => ['nullable', 'string', 'max:255'],
            'marital_status' => ['nullable', 'in:'.implode(',', self::MARITAL)],
            'mobile' => ['required', 'regex:/^01[3-9]\d{8}$/'],
            'emergency_mobile' => ['nullable', 'regex:/^01[3-9]\d{8}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:50'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'bank_branch_name' => ['nullable', 'string', 'max:255'],
            'branch_routing_number' => ['nullable', 'string', 'max:20'],
            'bank_comments' => ['nullable', 'string'],
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:2048'],
        ];
        if ($cfg['table'] === 'employees') {
            $rules += [
                'branch_id' => ['required', 'integer', 'exists:branches,id'],
                'joining_date' => ['required', 'date'],
                'department_id' => ['nullable', 'integer', 'exists:departments,id'],
                'designation_id' => ['nullable', 'integer', 'exists:designations,id'],
                'employee_type_id' => ['nullable', 'integer', 'exists:employee_types,id'],
                'manager_id' => ['nullable', 'integer', 'exists:employees,id'],
                'status' => ['nullable', 'in:Active,Inactive'],
            ];
        } else {
            $rules['director_status'] = ['nullable', 'in:Active,Inactive'];
        }

        $data = $request->validate($rules, [
            'national_id.regex' => __('invalid_nid_format'),
            'mobile.regex' => __('invalid_mobile_format'),
            'emergency_mobile.regex' => __('invalid_mobile_format'),
        ]);
        unset($data['photo']);

        // Cross-register duplicate checks (employees + directors + members).
        $exceptTable = $item ? $cfg['table'] : null;
        $exceptId = $item?->id;
        if (! empty($data['national_id']) && $identity->nidTaken($data['national_id'], $exceptTable, $exceptId)) {
            throw ValidationException::withMessages(['national_id' => __('duplicate_nid')]);
        }
        if (! empty($data['passport_no']) && $identity->passportTaken($data['passport_no'], $exceptTable, $exceptId)) {
            throw ValidationException::withMessages(['passport_no' => __('duplicate_passport')]);
        }
        if ($identity->mobileTaken($data['mobile'], $exceptTable, $exceptId)) {
            throw ValidationException::withMessages(['mobile' => __('duplicate_mobile')]);
        }

        // An untouched status select submits '' (converted to null), and a
        // null passed explicitly to create()/update() overrides the column
        // default and violates NOT NULL — fall back to 'Active' here so the
        // register forms can never persist a null status.
        if ($cfg['table'] === 'employees') {
            $data['status'] = ! empty($data['status']) ? $data['status'] : 'Active';
        } else {
            $data['director_status'] = ! empty($data['director_status']) ? $data['director_status'] : 'Active';
        }

        return $data;
    }
}
