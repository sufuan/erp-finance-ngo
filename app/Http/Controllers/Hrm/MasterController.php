<?php

namespace App\Http\Controllers\Hrm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Designation;
use App\Models\EmployeeType;
use App\Models\Shift;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR masters (Step 5): departments, designations, employee types, shifts.
 * One field-config-driven controller + page; every field of the plan's
 * table definitions is editable.
 */
class MasterController extends Controller
{
    private function config(): array
    {
        $text = fn (string $key, bool $required = true) => ['key' => $key, 'type' => 'text', 'required' => $required];
        $time = fn (string $key, bool $required = true) => ['key' => $key, 'type' => 'time', 'required' => $required];
        $int = fn (string $key, bool $required = false) => ['key' => $key, 'type' => 'number', 'required' => $required];
        $active = ['key' => 'is_active', 'type' => 'checkbox', 'required' => false];

        return [
            'departments' => ['model' => Department::class, 'label' => 'department', 'fields' => [
                ['key' => 'branch_id', 'type' => 'select', 'required' => true, 'options' => 'branches'],
                $text('name'), $text('location', false), $int('incharge_id'), $active,
            ]],
            'designations' => ['model' => Designation::class, 'label' => 'designation', 'fields' => [$text('name'), $active]],
            'employee-types' => ['model' => EmployeeType::class, 'label' => 'employment_type', 'fields' => [$text('name'), $active]],
            'shifts' => ['model' => Shift::class, 'label' => 'shift', 'fields' => [
                $text('name'), $time('start_time'), $time('end_time'), $int('grace_minutes', true),
                $time('refresh_start', false), $time('refresh_end', false),
                $time('overtime_start', false), $time('overtime_end', false),
                $int('monthly_duty_days'), $int('daily_duty_minutes'), $int('allowed_late_days'),
                ['key' => 'default_bonus_percent', 'type' => 'number', 'required' => false],
                ['key' => 'default_deduction_percent', 'type' => 'number', 'required' => false],
                $active,
            ]],
        ];
    }

    private function entity(string $entity): array
    {
        $config = $this->config();
        abort_unless(isset($config[$entity]), 404);

        return $config[$entity];
    }

    public function index(string $entity): Response
    {
        $cfg = $this->entity($entity);
        $model = $cfg['model'];
        $items = $model::orderBy('name')->get()->map(function ($item) {
            $row = $item->toArray();
            if ($item instanceof Department) {
                $row['branch'] = $item->branch?->only(['id', 'code', 'name']);
            }

            return $row;
        });

        $fields = collect($cfg['fields'])->map(function ($field) {
            if (($field['options'] ?? null) === 'branches') {
                $field['options'] = Branch::orderBy('code')->get(['id', 'code', 'name'])
                    ->map(fn ($b) => ['id' => $b->id, 'name' => "{$b->code} — {$b->name}"])->all();
            }
            $field['label'] = $field['key'] === 'branch_id' ? 'branch' : $field['key'];

            return $field;
        })->all();

        return Inertia::render('Hrm/Masters/Index', [
            'entity' => $entity,
            'label' => $cfg['label'],
            'items' => $items,
            'fields' => $fields,
            'entities' => collect(array_keys($this->config()))->map(fn ($slug) => ['slug' => $slug, 'label' => $this->config()[$slug]['label']])->all(),
        ]);
    }

    public function store(Request $request, string $entity, AuditService $audit): RedirectResponse
    {
        $cfg = $this->entity($entity);
        $data = $this->validateData($request, $cfg);
        $model = $cfg['model'];
        $item = $model::create($data);
        $audit->log($request->user()->id, 'hrm.created', $entity, $item->id, null, ['name' => $item->name]);

        return back()->with('success', __('saved'));
    }

    public function update(Request $request, string $entity, int $id, AuditService $audit): RedirectResponse
    {
        $cfg = $this->entity($entity);
        $model = $cfg['model'];
        $item = $model::findOrFail($id);
        $item->update($this->validateData($request, $cfg));
        $audit->log($request->user()->id, 'hrm.updated', $entity, $item->id, null, ['name' => $item->name]);

        return back()->with('success', __('saved'));
    }

    public function destroy(Request $request, string $entity, int $id, AuditService $audit): RedirectResponse
    {
        $cfg = $this->entity($entity);
        $model = $cfg['model'];
        $item = $model::findOrFail($id);
        $audit->log($request->user()->id, 'hrm.deleted', $entity, $item->id, ['name' => $item->name], null);
        $item->delete();

        return back()->with('success', __('saved'));
    }

    private function validateData(Request $request, array $cfg): array
    {
        $rules = [];
        foreach ($cfg['fields'] as $field) {
            $rules[$field['key']] = match ($field['type']) {
                'checkbox' => ['nullable', 'boolean'],
                'time' => [$field['required'] ? 'required' : 'nullable', 'date_format:H:i'],
                'number' => [$field['required'] ? 'required' : 'nullable', 'numeric'],
                'select' => [$field['required'] ? 'required' : 'nullable', 'integer', 'exists:branches,id'],
                default => [$field['required'] ? 'required' : 'nullable', 'string', 'max:255'],
            };
        }
        $data = $request->validate($rules);
        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = (bool) ($data['is_active'] ?? false);
        }

        return $data;
    }
}
