<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Notice;
use App\Models\Samity;
use App\Models\SamityDayChange;
use App\Models\WorkingArea;
use App\Services\AuditService;
use App\Services\BranchAccessService;
use App\Services\NumberingService;
use App\Services\SamityAccessService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 7 — samity lifecycle: opening, day/officer/manager changes with
 * history, holidays, topbar notices, merge & split.
 */
class SamityController extends Controller
{
    private const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    private function businessDate(Request $request): string
    {
        $date = $request->attributes->get('business_date');

        return $date ? Carbon::parse($date)->toDateString() : now()->toDateString();
    }

    private function nextCode(NumberingService $numbering): string
    {
        $head = Branch::where('type', 'Head Office')->first() ?? Branch::orderBy('id')->first();

        return str_pad((string) $numbering->next($head->id, 'samity'), 4, '0', STR_PAD_LEFT);
    }

    private function formOptions(Request $request): array
    {
        $branches = app(BranchAccessService::class)->visibleBranches($request->user());

        return [
            'branches' => $branches->map(fn ($b) => ['id' => $b->id, 'name' => "{$b->code} — {$b->name}"])->all(),
            'workingAreas' => WorkingArea::orderBy('name')->get(['id', 'name'])->all(),
            'employees' => Employee::where('status', 'Active')->orderBy('name')->get(['id', 'employee_code', 'name'])
                ->map(fn ($e) => ['id' => $e->id, 'name' => "{$e->employee_code} — {$e->name}"])->all(),
            'days' => collect(self::DAYS)->map(fn ($d) => ['id' => $d, 'name' => $d, 'label_key' => 'day_'.strtolower($d)])->all(),
            'centerTypes' => [
                ['id' => 'All Gender', 'name' => 'All Gender', 'label_key' => 'center_all_gender'],
                ['id' => 'Male', 'name' => 'Male', 'label_key' => 'gender_male'],
                ['id' => 'Female', 'name' => 'Female', 'label_key' => 'gender_female'],
            ],
        ];
    }

    public function index(Request $request): Response
    {
        $active = $request->attributes->get('active_branch');
        $samities = Samity::with(['branch', 'workingArea', 'fieldOfficer', 'manager'])
            ->when($active, fn ($q) => $q->where('branch_id', $active->id))
            ->orderBy('samity_code')
            ->get()
            ->map(fn (Samity $s) => [
                ...$s->toArray(),
                'branch' => $s->branch?->only(['id', 'code', 'name']),
                'working_area' => $s->workingArea?->only(['id', 'name']),
                'field_officer' => $s->fieldOfficer?->only(['id', 'name']),
                'manager' => $s->manager?->only(['id', 'name']),
                'member_count' => Schema::hasTable('members') ? $s->members()->count() : 0,
            ]);

        return Inertia::render('Settings/Samities/Index', [
            'samities' => $samities,
            'options' => $this->formOptions($request),
            'businessDate' => $this->businessDate($request),
            'activeBranchId' => $active?->id,
        ]);
    }

    /**
     * Meeting schedule: a samity meets EITHER weekly (samity_day set,
     * date_of_month null) OR monthly (date_of_month 1–31, or 0 = last day
     * of the month; samity_day null). The payload's meeting_frequency
     * drives which field is required; carrying the other field's value
     * is a mismatch and is rejected rather than silently dropped.
     */
    private function meetingScheduleRules(): array
    {
        return [
            'meeting_frequency' => ['required', 'in:weekly,monthly'],
            'samity_day' => ['nullable', 'required_if:meeting_frequency,weekly', 'in:'.implode(',', self::DAYS)],
            'date_of_month' => ['nullable', 'required_if:meeting_frequency,monthly', 'integer', 'min:0', 'max:31'],
        ];
    }

    /** Reject the inactive field carrying a value; null it out; drop the frequency flag. */
    private function normalizeSchedule(array $data): array
    {
        if ($data['meeting_frequency'] === 'weekly') {
            if (isset($data['date_of_month']) && $data['date_of_month'] !== null && $data['date_of_month'] !== '') {
                throw ValidationException::withMessages(['date_of_month' => __('meeting_schedule_conflict')]);
            }
            $data['date_of_month'] = null;
        } else {
            if (! empty($data['samity_day'])) {
                throw ValidationException::withMessages(['samity_day' => __('meeting_schedule_conflict')]);
            }
            $data['samity_day'] = null;
            $data['date_of_month'] = (int) $data['date_of_month'];
        }
        unset($data['meeting_frequency']);

        return $data;
    }

    /** Display token for a schedule: day name, "monthly:15", or "monthly:last". */
    private function scheduleToken(?string $day, mixed $dateOfMonth): ?string
    {
        if ($dateOfMonth !== null && $dateOfMonth !== '') {
            return (int) $dateOfMonth === 0 ? 'monthly:last' : 'monthly:'.(int) $dateOfMonth;
        }

        return $day;
    }

    private function samityScheduleToken(Samity $samity): ?string
    {
        return $this->scheduleToken($samity->samity_day, $samity->date_of_month);
    }

    private function validateSamity(Request $request): array
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:255'],
            'referred_by' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'open_date' => ['required', 'date'],
            'working_area_id' => ['nullable', 'integer', 'exists:working_areas,id'],
            'field_officer_id' => ['nullable', 'integer', 'exists:employees,id'],
            'samity_phone' => ['nullable', 'string', 'max:20'],
            'center_type' => ['required', 'in:All Gender,Male,Female'],
            'start_time' => ['nullable', 'date_format:H:i'],
            ...$this->meetingScheduleRules(),
            'manager_id' => ['nullable', 'integer', 'exists:employees,id'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        return $this->normalizeSchedule($data);
    }

    public function store(Request $request, NumberingService $numbering, AuditService $audit): RedirectResponse
    {
        $data = $this->validateSamity($request);
        $data['samity_code'] = $this->nextCode($numbering);
        $data['created_by'] = $request->user()->id;
        $data['updated_by'] = $request->user()->id;
        $samity = Samity::create($data);
        $audit->log($request->user()->id, 'samity.created', 'samities', $samity->id, null, ['name' => $samity->name, 'code' => $samity->samity_code]);

        return back()->with('success', __('saved'));
    }

    public function update(Request $request, Samity $samity, AuditService $audit, SamityAccessService $access): RedirectResponse
    {
        $access->assertAccess($request->user(), $samity->id);
        $data = $this->validateSamity($request);
        $data['updated_by'] = $request->user()->id;
        $samity->update($data);
        $audit->log($request->user()->id, 'samity.updated', 'samities', $samity->id, null, ['name' => $samity->name]);

        return back()->with('success', __('saved'));
    }

    // --- Day / officer / manager changes ---------------------------------

    private function changeConfig(string $type): array
    {
        return match ($type) {
            'day' => ['field' => 'samity_day', 'model' => \App\Models\SamityDayChange::class, 'label' => 'samity_day_change'],
            'officer' => ['field' => 'field_officer_id', 'model' => \App\Models\SamityOfficerChange::class, 'label' => 'samity_officer_change'],
            'manager' => ['field' => 'manager_id', 'model' => \App\Models\SamityManagerChange::class, 'label' => 'samity_manager_change'],
            default => abort(404),
        };
    }

    public function changeForm(Request $request, string $type): Response
    {
        $cfg = $this->changeConfig($type);
        $model = $cfg['model'];
        $samityId = (int) $request->query('samity_id', 0);
        if ($samityId) {
            app(SamityAccessService::class)->assertAccess($request->user(), $samityId);
        }

        $samities = Samity::orderBy('samity_code')->get()->map(fn (Samity $s) => [
            'id' => $s->id, 'samity_code' => $s->samity_code, 'name' => $s->name,
            'current' => $cfg['field'] === 'samity_day' ? $this->samityScheduleToken($s) : $s->{$cfg['field']},
            'current_label' => $cfg['field'] === 'samity_day'
                ? $this->samityScheduleToken($s)
                : $this->changeValueLabel($cfg, $s->{$cfg['field']}),
            'current_day' => $s->samity_day,
            'current_date' => $s->date_of_month,
        ]);

        $history = $model::with('samity')->orderByDesc('id')->limit(100)->get()->map(fn ($row) => [
            ...$row->toArray(),
            'samity' => $row->samity?->only(['id', 'samity_code', 'name']),
            'previous_label' => $this->changeValueLabel($cfg, $row->previous_value),
            'new_label' => $this->changeValueLabel($cfg, $row->new_value),
        ]);

        return Inertia::render('Settings/Samities/Changes', [
            'type' => $type,
            'label' => $cfg['label'],
            'field' => $cfg['field'],
            'samities' => $samities,
            'history' => $history,
            'options' => $this->formOptions($request),
            'businessDate' => $this->businessDate($request),
            'selectedSamityId' => $samityId ?: null,
        ]);
    }

    private function changeValueLabel(array $cfg, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($cfg['field'] === 'samity_day') {
            return $value;
        }

        return Employee::find((int) $value)?->name ?? (string) $value;
    }

    public function applyChange(Request $request, string $type, AuditService $audit, SamityAccessService $access): RedirectResponse
    {
        $cfg = $this->changeConfig($type);

        // Day changes carry the whole meeting schedule (frequency + day or
        // monthly date), so a change can also switch weekly <-> monthly.
        // The history row stores schedule tokens ("Wednesday", "monthly:15",
        // "monthly:last") as previous/new values, and BOTH schedule columns
        // are updated together so the inactive one is always null.
        if ($type === 'day') {
            $data = $request->validate([
                'samity_id' => ['required', 'integer', 'exists:samities,id'],
                ...$this->meetingScheduleRules(),
                'change_date' => ['required', 'date'],
                'effective_from' => ['required', 'date'],
                'comments' => ['nullable', 'string'],
            ]);
            $data = $this->normalizeSchedule($data);
            $access->assertAccess($request->user(), (int) $data['samity_id']);

            $samity = Samity::findOrFail($data['samity_id']);
            $previousToken = $this->samityScheduleToken($samity);
            $newToken = $this->scheduleToken($data['samity_day'], $data['date_of_month']);
            SamityDayChange::create([
                'samity_id' => $samity->id,
                'change_date' => $data['change_date'],
                'effective_from' => $data['effective_from'],
                'previous_value' => $previousToken,
                'new_value' => $newToken,
                'comments' => $data['comments'] ?? null,
                'created_by' => $request->user()->id,
            ]);
            $samity->update([
                'samity_day' => $data['samity_day'],
                'date_of_month' => $data['date_of_month'],
                'updated_by' => $request->user()->id,
            ]);
            $audit->log($request->user()->id, 'samity.day_changed', 'samities', $samity->id,
                ['schedule' => $previousToken], ['schedule' => $newToken]);

            return back()->with('success', __('saved'));
        }

        $data = $request->validate([
            'samity_id' => ['required', 'integer', 'exists:samities,id'],
            'new_value' => ['required', 'integer'],
            'change_date' => ['required', 'date'],
            'effective_from' => ['required', 'date'],
            'comments' => ['nullable', 'string'],
        ]);
        $request->validate(['new_value' => ['exists:employees,id']]);
        $access->assertAccess($request->user(), (int) $data['samity_id']);

        $samity = Samity::findOrFail($data['samity_id']);
        $previous = $samity->{$cfg['field']};
        $model = $cfg['model'];
        $model::create([
            'samity_id' => $samity->id,
            'change_date' => $data['change_date'],
            'effective_from' => $data['effective_from'],
            'previous_value' => $previous !== null ? (string) $previous : null,
            'new_value' => (string) $data['new_value'],
            'comments' => $data['comments'] ?? null,
            'created_by' => $request->user()->id,
        ]);
        $samity->update([$cfg['field'] => $data['new_value'], 'updated_by' => $request->user()->id]);
        $audit->log($request->user()->id, 'samity.'.$type.'_changed', 'samities', $samity->id,
            [$cfg['field'] => $previous], [$cfg['field'] => $data['new_value']]);

        return back()->with('success', __('saved'));
    }

    // --- Holidays ----------------------------------------------------------

    public function holidays(Request $request): Response
    {
        $active = $request->attributes->get('active_branch');
        $holidays = Holiday::with(['branch', 'samity'])
            ->when($active, fn ($q) => $q->where('branch_id', $active->id))
            ->orderByDesc('holiday_date')
            ->limit(200)
            ->get()
            ->map(fn (Holiday $h) => [
                ...$h->toArray(),
                'branch' => $h->branch?->only(['id', 'code', 'name']),
                'samity' => $h->samity?->only(['id', 'name']),
            ]);

        return Inertia::render('Settings/Samities/Holidays', [
            'holidays' => $holidays,
            'options' => $this->formOptions($request),
            'samities' => Samity::orderBy('name')->get(['id', 'name'])->all(),
            'activeBranchId' => $active?->id,
            'businessDate' => $this->businessDate($request),
        ]);
    }

    public function storeHoliday(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'samity_id' => ['nullable', 'integer', 'exists:samities,id'],
            'details' => ['required', 'string', 'max:255'],
            'holiday_date' => ['nullable', 'date'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $dates = [];
        if (! empty($data['holiday_date'])) {
            $dates[] = Carbon::parse($data['holiday_date']);
        } elseif (! empty($data['date_from']) && ! empty($data['date_to'])) {
            $from = Carbon::parse($data['date_from']);
            $to = Carbon::parse($data['date_to']);
            if ($from->diffInDays($to) + 1 > 31) {
                throw ValidationException::withMessages(['date_to' => __('holiday_range_too_long')]);
            }
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                $dates[] = $d->copy();
            }
        } else {
            throw ValidationException::withMessages(['holiday_date' => __('holiday_date_required')]);
        }

        $created = 0;
        foreach ($dates as $date) {
            $exists = Holiday::where('branch_id', $data['branch_id'])
                ->where('holiday_date', $date->toDateString())
                ->where('samity_id', $data['samity_id'] ?? null)
                ->exists();
            if ($exists) {
                continue; // existing dates are skipped, per plan
            }
            Holiday::create([
                'branch_id' => $data['branch_id'],
                'samity_id' => $data['samity_id'] ?? null,
                'holiday_date' => $date->toDateString(),
                'details' => $data['details'],
                'created_by' => $request->user()->id,
            ]);
            $created++;
        }
        $audit->log($request->user()->id, 'holiday.created', 'holidays', null, null, ['count' => $created]);

        return back()->with('success', __('holidays_added', ['count' => $created]));
    }

    public function destroyHoliday(Request $request, Holiday $holiday, AuditService $audit): RedirectResponse
    {
        $audit->log($request->user()->id, 'holiday.deleted', 'holidays', $holiday->id, ['date' => $holiday->holiday_date], null);
        $holiday->delete();

        return back()->with('success', __('saved'));
    }

    // --- Notices -----------------------------------------------------------

    public function notices(): Response
    {
        return Inertia::render('Settings/Samities/Notices', [
            'notices' => Notice::orderByDesc('id')->limit(50)->get(),
            'colors' => ['Red', 'Green', 'Yellow', 'Black'],
        ]);
    }

    public function storeNotice(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:500'],
            'text_color' => ['required', 'in:Red,Green,Yellow,Black'],
        ]);
        $notice = Notice::create([...$data, 'created_by' => $request->user()->id]);
        $audit->log($request->user()->id, 'notice.created', 'notices', $notice->id, null, ['color' => $notice->text_color]);

        return back()->with('success', __('saved'));
    }

    public function destroyNotice(Request $request, Notice $notice, AuditService $audit): RedirectResponse
    {
        $audit->log($request->user()->id, 'notice.deleted', 'notices', $notice->id, null, null);
        $notice->delete();

        return back()->with('success', __('saved'));
    }

    // --- Merge & split -----------------------------------------------------

    /** Members with an active loan block a merge unless a manager approves. */
    private function samityHasActiveLoans(int $samityId): bool
    {
        if (! Schema::hasTable('members') || ! Schema::hasTable('loan_accounts')) {
            return false;
        }

        return DB::table('loan_accounts')
            ->join('members', 'members.id', '=', 'loan_accounts.member_id')
            ->where('members.samity_id', $samityId)
            ->where('loan_accounts.status', 'Active')
            ->exists();
    }

    public function mergeForm(): Response
    {
        return Inertia::render('Settings/Samities/Merge', [
            'samities' => Samity::orderBy('samity_code')->get(['id', 'samity_code', 'name', 'branch_id'])
                ->map(fn (Samity $s) => [
                    'id' => $s->id, 'name' => "{$s->samity_code} — {$s->name}", 'branch_id' => $s->branch_id,
                    'member_count' => Schema::hasTable('members') ? $s->members()->count() : 0,
                ])->all(),
        ]);
    }

    public function merge(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'source_samity_id' => ['required', 'integer', 'exists:samities,id', 'different:target_samity_id'],
            'target_samity_id' => ['required', 'integer', 'exists:samities,id'],
            'manager_approved' => ['nullable', 'boolean'],
        ]);
        $source = Samity::findOrFail($data['source_samity_id']);
        $target = Samity::findOrFail($data['target_samity_id']);
        if ($source->branch_id !== $target->branch_id) {
            throw ValidationException::withMessages(['target_samity_id' => __('merge_same_branch_only')]);
        }
        if ($this->samityHasActiveLoans($source->id) && empty($data['manager_approved'])) {
            throw ValidationException::withMessages(['manager_approved' => __('merge_blocked_active_loans')]);
        }

        DB::transaction(function () use ($request, $source, $target, $audit) {
            $moved = 0;
            if (Schema::hasTable('members')) {
                foreach ($source->members()->pluck('id') as $memberId) {
                    DB::table('members')->where('id', $memberId)->update(['samity_id' => $target->id]);
                    $audit->log($request->user()->id, 'member.samity_merged', 'members', $memberId,
                        ['samity_id' => $source->id], ['samity_id' => $target->id]);
                    $moved++;
                }
            }
            $source->update(['status' => 'Merged', 'closed_by' => $request->user()->id, 'closed_at' => now()]);
            $audit->log($request->user()->id, 'samity.merged', 'samities', $source->id,
                ['status' => 'Active'], ['status' => 'Merged', 'into' => $target->id, 'members_moved' => $moved]);
        });

        return back()->with('success', __('saved'));
    }

    public function splitForm(): Response
    {
        $members = Schema::hasTable('members')
            ? DB::table('members')->where('status', 'Active')->orderBy('member_no')
                ->get(['id', 'member_no', 'name', 'samity_id'])->all()
            : [];

        return Inertia::render('Settings/Samities/Split', [
            'samities' => Samity::orderBy('samity_code')->get(['id', 'samity_code', 'name', 'branch_id', 'samity_day', 'center_type', 'working_area_id', 'field_officer_id', 'manager_id', 'address'])
                ->map(fn (Samity $s) => ['id' => $s->id, 'name' => "{$s->samity_code} — {$s->name}", 'source' => $s])->all(),
            'members' => $members,
            'options' => $this->formOptions(request()),
        ]);
    }

    public function split(Request $request, NumberingService $numbering, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'source_samity_id' => ['required', 'integer', 'exists:samities,id'],
            'member_ids' => ['required', 'array', 'min:1'],
            'member_ids.*' => ['integer'],
            'manager_approved' => ['nullable', 'boolean'],
        ]);
        $samityData = $this->validateSamity($request);
        $source = Samity::findOrFail($data['source_samity_id']);

        $blockedMember = false;
        if (Schema::hasTable('loan_accounts')) {
            $blockedMember = DB::table('loan_accounts')
                ->whereIn('member_id', $data['member_ids'])
                ->where('status', 'Active')
                ->exists();
        }
        if ($blockedMember && empty($data['manager_approved'])) {
            throw ValidationException::withMessages(['manager_approved' => __('merge_blocked_active_loans')]);
        }

        DB::transaction(function () use ($request, $data, $samityData, $source, $numbering, $audit) {
            $new = Samity::create([
                ...$samityData,
                'samity_code' => $this->nextCode($numbering),
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);
            if (Schema::hasTable('members')) {
                foreach ($data['member_ids'] as $memberId) {
                    DB::table('members')->where('id', $memberId)->where('samity_id', $source->id)
                        ->update(['samity_id' => $new->id]);
                    $audit->log($request->user()->id, 'member.samity_split', 'members', (int) $memberId,
                        ['samity_id' => $source->id], ['samity_id' => $new->id]);
                }
            }
            $audit->log($request->user()->id, 'samity.split', 'samities', $new->id, null,
                ['from' => $source->id, 'members_moved' => count($data['member_ids'])]);
        });

        return back()->with('success', __('saved'));
    }
}
