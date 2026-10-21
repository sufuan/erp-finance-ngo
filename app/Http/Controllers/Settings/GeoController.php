<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Village;
use App\Models\WorkingArea;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manual geography masters (reworked 2026-10-21): only villages and
 * working areas remain in the database. Divisions, districts, upazilas
 * and unions come from the `bd-geo-info` npm package in the browser;
 * the forms submit the selected chain as PLAIN values (package id as
 * string + name) which are stored verbatim — no geo foreign keys.
 * The working area's village is still a real database relation.
 */
class GeoController extends Controller
{
    /** Geo chain columns stored as plain package values on both entities. */
    private const CHAIN = ['division', 'district', 'upazila', 'union'];

    private function config(): array
    {
        return [
            'villages' => ['model' => Village::class, 'label' => 'village'],
            'working-areas' => ['model' => WorkingArea::class, 'label' => 'working_area'],
        ];
    }

    private function entity(string $entity): array
    {
        $config = $this->config();
        abort_unless(isset($config[$entity]), 404);

        return $config[$entity];
    }

    public function index(Request $request, string $entity): Response
    {
        $cfg = $this->entity($entity);
        $model = $cfg['model'];
        $perPage = in_array((int) $request->input('per_page', 10), [10, 25, 50, 100], true) ? (int) $request->input('per_page', 10) : 10;

        $query = $model::query()->orderBy('name');
        if ($entity === 'working-areas') {
            $query->with('village:id,name');
        }
        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%");
        }
        $items = $query->paginate($perPage)->withQueryString();

        $items->getCollection()->transform(function (Model $item) {
            $row = ['id' => $item->id, 'name' => $item->name];
            foreach (self::CHAIN as $level) {
                $row["{$level}_id"] = $item->{"{$level}_id"};
                $row["{$level}_name"] = $item->{"{$level}_name"};
            }
            if ($item instanceof WorkingArea) {
                $row['village_id'] = $item->village_id;
                $row['village'] = $item->village?->only(['id', 'name']);
            }

            return $row;
        });

        // Only the working area has a database parent left: the village.
        $parentFields = [];
        if ($entity === 'working-areas') {
            $parentFields[] = [
                'key' => 'village_id', 'label' => 'village', 'depends_on' => null,
                'options' => Village::orderBy('name')->get(['id', 'name'])
                    ->map(fn (Village $v) => ['id' => $v->id, 'name' => $v->name])->all(),
            ];
        }

        return Inertia::render('Settings/Geo/Index', [
            'entity' => $entity,
            'label' => $cfg['label'],
            'items' => $items,
            'parentFields' => $parentFields,
            'filters' => ['search' => $request->input('search', ''), 'per_page' => $perPage],
            'entities' => collect(array_keys($this->config()))->map(fn ($slug) => ['slug' => $slug, 'label' => $this->config()[$slug]['label']])->all(),
        ]);
    }

    public function store(Request $request, string $entity, AuditService $audit): RedirectResponse
    {
        $cfg = $this->entity($entity);
        $data = $this->validateEntity($request, $cfg);
        $model = $cfg['model'];
        $item = $model::create($data);
        $audit->log($request->user()->id, 'geo.created', $entity, $item->id, null, ['name' => $item->name]);

        return back()->with('success', __('saved'));
    }

    public function update(Request $request, string $entity, string $id, AuditService $audit): RedirectResponse
    {
        $cfg = $this->entity($entity);
        $model = $cfg['model'];
        $item = $model::findOrFail($id);
        $data = $this->validateEntity($request, $cfg);
        $item->update($data);
        $audit->log($request->user()->id, 'geo.updated', $entity, $item->id, null, ['name' => $item->name]);

        return back()->with('success', __('saved'));
    }

    public function destroy(Request $request, string $entity, string $id, AuditService $audit): RedirectResponse
    {
        $cfg = $this->entity($entity);
        $model = $cfg['model'];
        $item = $model::findOrFail($id);

        // No child guards remain: working_areas.village_id is
        // nullOnDelete, so removing a village just unlinks its areas.
        $audit->log($request->user()->id, 'geo.deleted', $entity, $item->id, ['name' => $item->name], null);
        $item->delete();

        return back()->with('success', __('saved'));
    }

    private function validateEntity(Request $request, array $cfg): array
    {
        $isVillage = $cfg['model'] === Village::class;
        $rules = ['name' => ['required', 'string', 'max:255']];
        // The cascade chain arrives as plain package values. A village
        // hangs under a union, so its chain must be complete; a working
        // area's chain is entirely optional.
        foreach (self::CHAIN as $level) {
            $rules["{$level}_id"] = [$isVillage ? 'required' : 'nullable', 'string', 'max:20'];
            $rules["{$level}_name"] = [$isVillage ? 'required' : 'nullable', 'string', 'max:255'];
        }
        if (! $isVillage) {
            $rules['village_id'] = ['nullable', 'integer', Rule::exists('villages', 'id')];
        }

        return $request->validate($rules);
    }
}
