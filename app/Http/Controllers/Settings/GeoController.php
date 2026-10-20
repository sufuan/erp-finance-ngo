<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\District;
use App\Models\Division;
use App\Models\Union;
use App\Models\Upazila;
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
 * Geographic masters (Step 4): one generic CRUD controller drives all
 * seven levels. Each entity declares its parents; the form's parent
 * selects cascade client-side and chain-consistency is enforced here.
 */
class GeoController extends Controller
{
    /**
     * entity slug => [model, labelKey, parents: [fk => [model, relation, dependsOn fk|null]]]
     */
    private function config(): array
    {
        return [
            'countries' => ['model' => Country::class, 'label' => 'country', 'parents' => []],
            'divisions' => ['model' => Division::class, 'label' => 'division', 'parents' => [
                'country_id' => ['model' => Country::class, 'relation' => 'country', 'label' => 'country', 'depends_on' => null],
            ]],
            'districts' => ['model' => District::class, 'label' => 'district', 'parents' => [
                'division_id' => ['model' => Division::class, 'relation' => 'division', 'label' => 'division', 'depends_on' => null],
            ]],
            'upazilas' => ['model' => Upazila::class, 'label' => 'upazila', 'parents' => [
                'district_id' => ['model' => District::class, 'relation' => 'district', 'label' => 'district', 'depends_on' => null],
            ]],
            'unions' => ['model' => Union::class, 'label' => 'union', 'parents' => [
                'upazila_id' => ['model' => Upazila::class, 'relation' => 'upazila', 'label' => 'upazila', 'depends_on' => null],
            ]],
            'villages' => ['model' => Village::class, 'label' => 'village', 'parents' => [
                'union_id' => ['model' => Union::class, 'relation' => 'union', 'label' => 'union', 'depends_on' => null],
            ]],
            'working-areas' => ['model' => WorkingArea::class, 'label' => 'working_area', 'parents' => [
                'division_id' => ['model' => Division::class, 'relation' => 'division', 'label' => 'division', 'depends_on' => null],
                'district_id' => ['model' => District::class, 'relation' => 'district', 'label' => 'district', 'depends_on' => 'division_id'],
                'upazila_id' => ['model' => Upazila::class, 'relation' => 'upazila', 'label' => 'upazila', 'depends_on' => 'district_id'],
                'union_id' => ['model' => Union::class, 'relation' => 'union', 'label' => 'union', 'depends_on' => 'upazila_id'],
                'village_id' => ['model' => Village::class, 'relation' => 'village', 'label' => 'village', 'depends_on' => 'union_id'],
            ]],
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
        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%");
        }
        $relations = array_values(array_map(fn ($p) => $p['relation'], $cfg['parents']));
        if ($relations) {
            $query->with($relations);
        }
        $items = $query->paginate($perPage)->withQueryString();

        $items->getCollection()->transform(function (Model $item) use ($cfg) {
            $row = ['id' => $item->id, 'name' => $item->name];
            foreach ($cfg['parents'] as $fk => $p) {
                $row[$fk] = $item->{$fk};
                $row[$p['relation']] = $item->{$p['relation']}?->only(['id', 'name']);
            }
            // Villages also carry the derived upazila.
            if ($item instanceof Village) {
                $row['upazila_id'] = $item->upazila_id;
                $row['upazila'] = $item->upazila?->only(['id', 'name']);
            }

            return $row;
        });

        // Parent options for the form, each carrying its own parent id so
        // selects can cascade (district options carry division_id, etc.).
        $parentFields = [];
        foreach ($cfg['parents'] as $fk => $p) {
            $parentModel = $p['model'];
            $options = $parentModel::orderBy('name')->get()->map(function (Model $o) {
                $opt = ['id' => $o->id, 'name' => $o->name];
                foreach (['country_id', 'division_id', 'district_id', 'upazila_id', 'union_id'] as $pfk) {
                    if (isset($o->{$pfk})) {
                        $opt[$pfk] = $o->{$pfk};
                    }
                }

                return $opt;
            });
            $parentFields[] = ['key' => $fk, 'label' => $p['label'], 'depends_on' => $p['depends_on'], 'options' => $options];
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

    public function update(Request $request, string $entity, int $id, AuditService $audit): RedirectResponse
    {
        $cfg = $this->entity($entity);
        $model = $cfg['model'];
        $item = $model::findOrFail($id);
        $data = $this->validateEntity($request, $cfg);
        $item->update($data);
        $audit->log($request->user()->id, 'geo.updated', $entity, $item->id, null, ['name' => $item->name]);

        return back()->with('success', __('saved'));
    }

    public function destroy(Request $request, string $entity, int $id, AuditService $audit): RedirectResponse
    {
        $cfg = $this->entity($entity);
        $model = $cfg['model'];
        $item = $model::findOrFail($id);

        // A parent with children cannot be deleted (children would orphan).
        $childRelations = match (true) {
            $item instanceof Country => ['divisions'],
            $item instanceof Division => ['districts'],
            $item instanceof District => ['upazilas'],
            $item instanceof Upazila => ['unions'],
            $item instanceof Union => ['villages'],
            default => [],
        };
        foreach ($childRelations as $relation) {
            if ($item->{$relation}()->exists()) {
                return back()->with('error', __('cannot_delete_with_children'));
            }
        }

        $audit->log($request->user()->id, 'geo.deleted', $entity, $item->id, ['name' => $item->name], null);
        $item->delete();

        return back()->with('success', __('saved'));
    }

    private function validateEntity(Request $request, array $cfg): array
    {
        $rules = ['name' => ['required', 'string', 'max:255']];
        foreach ($cfg['parents'] as $fk => $p) {
            $nullable = $cfg['model'] === WorkingArea::class;
            $rules[$fk] = [$nullable ? 'nullable' : 'required', 'integer', Rule::exists($p['model'], 'id')];
        }
        $data = $request->validate($rules);

        // Village's upazila is derived from its union — always consistent.
        if ($cfg['model'] === Village::class) {
            $data['upazila_id'] = Union::findOrFail($data['union_id'])->upazila_id;
        }

        // WorkingArea chain consistency: each chosen parent must belong
        // to the chosen grandparent.
        if ($cfg['model'] === WorkingArea::class) {
            if (! empty($data['district_id']) && ! empty($data['division_id'])) {
                abort_unless(District::find($data['district_id'])->division_id === (int) $data['division_id'], 422, __('geo_chain_mismatch'));
            }
            if (! empty($data['upazila_id']) && ! empty($data['district_id'])) {
                abort_unless(Upazila::find($data['upazila_id'])->district_id === (int) $data['district_id'], 422, __('geo_chain_mismatch'));
            }
            if (! empty($data['union_id']) && ! empty($data['upazila_id'])) {
                abort_unless(Union::find($data['union_id'])->upazila_id === (int) $data['upazila_id'], 422, __('geo_chain_mismatch'));
            }
            if (! empty($data['village_id']) && ! empty($data['union_id'])) {
                abort_unless(Village::find($data['village_id'])->union_id === (int) $data['union_id'], 422, __('geo_chain_mismatch'));
            }
        }

        return $data;
    }
}
