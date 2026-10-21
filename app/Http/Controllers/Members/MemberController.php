<?php

namespace App\Http\Controllers\Members;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberDocument;
use App\Models\MemberVisit;
use App\Models\Nominee;
use App\Models\OrganizationProfile;
use App\Models\Samity;
use App\Services\AuditService;
use App\Services\BranchAccessService;
use App\Services\IdentityCheckService;
use App\Services\MemberAdmissionService;
use App\Services\UploadService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 8 — member admission, list, 360 details, documents, visits.
 */
class MemberController extends Controller
{
    private const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
    private const MARITAL = ['Single', 'Married', 'Widowed', 'Divorced'];
    private const GENDERS = ['Male', 'Female', 'Third Gender'];
    private const RELIGIONS = ['Islam', 'Hinduism', 'Buddhism', 'Christianity', 'Other'];
    private const DOC_TYPES = ['NID', 'Photo', 'Signature', 'Passport', 'TIN Certificate', 'Other'];

    private function businessDate(Request $request): string
    {
        $date = $request->attributes->get('business_date');

        return $date ? Carbon::parse($date)->toDateString() : now()->toDateString();
    }

    private function formOptions(Request $request): array
    {
        $active = $request->attributes->get('active_branch');
        $samities = Samity::with('branch')
            ->when($active, fn ($q) => $q->where('branch_id', $active->id))
            ->orderBy('samity_code')
            ->get()
            ->map(fn (Samity $s) => ['id' => $s->id, 'name' => "{$s->samity_code} — {$s->name}"]);

        return [
            'samities' => $samities->all(),
            'genders' => collect(self::GENDERS)->map(fn ($g) => ['id' => $g, 'name' => $g, 'label_key' => 'gender_'.strtolower(str_replace(' ', '_', $g))])->all(),
            'religions' => collect(self::RELIGIONS)->map(fn ($r) => ['id' => $r, 'name' => $r])->all(),
            'bloodGroups' => collect(self::BLOOD_GROUPS)->map(fn ($b) => ['id' => $b, 'name' => $b])->all(),
            'marital' => collect(self::MARITAL)->map(fn ($m) => ['id' => $m, 'name' => $m, 'label_key' => 'marital_'.strtolower($m)])->all(),
            'docTypes' => self::DOC_TYPES,
        ];
    }

    public function index(Request $request): Response
    {
        $active = $request->attributes->get('active_branch');
        $filters = $request->only(['samity_id', 'search', 'phone', 'member_no', 'nid', 'status', 'gender', 'open_from', 'open_to']);

        $query = Member::with(['samity', 'memberUser'])
            ->when($active, fn ($q) => $q->where('members.branch_id', $active->id))
            ->when($filters['samity_id'] ?? null, fn ($q, $v) => $q->where('members.samity_id', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where('members.name', 'like', "%{$v}%"))
            ->when($filters['phone'] ?? null, fn ($q, $v) => $q->where('members.mobile', 'like', "%{$v}%"))
            ->when($filters['member_no'] ?? null, fn ($q, $v) => $q->where('members.member_no', 'like', "%{$v}%"))
            ->when($filters['nid'] ?? null, fn ($q, $v) => $q->where('members.nid_hash', IdentityCheckService::nidHash($v)))
            ->when(($filters['status'] ?? 'All') !== 'All', fn ($q) => $q->where('members.status', $filters['status']))
            ->when($filters['gender'] ?? null, fn ($q, $v) => $q->where('members.gender', $v))
            ->when($filters['open_from'] ?? null, fn ($q, $v) => $q->where('members.open_date', '>=', $v))
            ->when($filters['open_to'] ?? null, fn ($q, $v) => $q->where('members.open_date', '<=', $v));

        $members = $query->orderByDesc('members.id')->paginate(25)->withQueryString()->through(fn (Member $m) => $this->row($m));

        return Inertia::render('Members/Index', [
            'members' => $members,
            'filters' => $filters,
            'options' => $this->formOptions($request),
        ]);
    }

    /** The 20-column member list row (plan Step 8). */
    private function row(Member $m): array
    {
        return [
            'id' => $m->id,
            'photo_url' => $m->profile_photo ? '/files/'.$m->profile_photo : null,
            'member_no' => $m->member_no,
            'name' => $m->name,
            'samity' => $m->samity?->only(['id', 'name']),
            'father_name' => $m->father_name,
            'mother_name' => $m->mother_name,
            'blood_group' => $m->blood_group,
            'gender' => $m->gender,
            'religion' => $m->religion,
            'marital_status' => $m->marital_status,
            'national_id' => $m->national_id,
            'present_address' => $m->present_address,
            'permanent_address' => $m->permanent_address,
            'mobile' => $m->mobile,
            'email' => $m->email,
            'open_date' => $m->open_date?->toDateString(),
            'birth_date' => $m->birth_date?->toDateString(),
            'username' => $m->memberUser?->username,
            'status' => $m->status,
            'share_amount' => (string) $m->share_amount,
        ];
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Members/Admission', [
            'options' => $this->formOptions($request),
            'businessDate' => $this->businessDate($request),
            'member' => null,
        ]);
    }

    public function store(Request $request, IdentityCheckService $identity, UploadService $uploads, AuditService $audit, MemberAdmissionService $admissions): RedirectResponse
    {
        $data = $this->validateMember($request, $identity);
        $samity = Samity::findOrFail($data['samity_id']);
        $this->assertBranchVisible($request, $samity->branch_id);

        if ($request->hasFile('profile_photo_file')) {
            $data['profile_photo'] = $uploads->store($request->file('profile_photo_file'), 'photo', 'members');
        }
        unset($data['profile_photo_file']);
        if ($request->hasFile('nominee_photo_file')) {
            $data['nominee_photo'] = $uploads->store($request->file('nominee_photo_file'), 'photo', 'nominees');
        }
        unset($data['nominee_photo_file']);

        $member = $admissions->admit($data, $samity, $request->user()->id);

        $audit->log($request->user()->id, 'member.created', 'members', $member->id, null, ['member_no' => $member->member_no, 'name' => $member->name]);

        return redirect()->route('members.admission')
            ->with('success', __('member_saved', ['id' => $member->member_no]))
            ->with('created_member_no', $member->member_no);
    }

    public function show(Member $member): Response
    {
        $member->load(['samity', 'branch', 'memberUser', 'nominees', 'documents', 'transfers', 'closings']);
        $visits = $member->visits()->orderByDesc('id')->limit(50)->get();

        return Inertia::render('Members/Show', [
            'member' => [
                ...$this->row($member),
                'spouse_name' => $member->spouse_name,
                'emergency_phone' => $member->emergency_phone,
                'passport_no' => $member->passport_no,
                'tin' => $member->tin,
                'occupation' => $member->occupation,
                'education' => $member->education,
                'branch' => $member->branch?->only(['id', 'code', 'name']),
                'username' => $member->memberUser?->username,
                'portal_active' => (bool) $member->memberUser?->is_active,
                'last_login_at' => $member->memberUser?->last_login_at?->toDateTimeString(),
            ],
            'nominees' => $member->nominees->map(fn (Nominee $n) => [
                ...$n->toArray(),
                'age' => $n->age,
                'photo_url' => $n->photo ? '/files/'.$n->photo : null,
            ])->all(),
            'documents' => $member->documents->map(fn (MemberDocument $d) => [
                ...$d->toArray(), 'file_url' => '/files/'.$d->file_path,
            ])->all(),
            'visits' => $visits->map(fn (MemberVisit $v) => $v->toArray())->all(),
            'transfers' => $member->transfers->map(fn ($t) => $t->toArray())->all(),
            'docTypes' => self::DOC_TYPES,
        ]);
    }

    public function edit(Request $request, Member $member): Response
    {
        $member->load('nominees');
        $nominee = $member->nominees->first();

        return Inertia::render('Members/Admission', [
            'options' => $this->formOptions($request),
            'businessDate' => $this->businessDate($request),
            'member' => [
                ...$member->toArray(),
                'photo_url' => $member->profile_photo ? '/files/'.$member->profile_photo : null,
                'nominee_name' => $nominee?->name,
                'nominee_dob' => $nominee?->dob?->toDateString(),
                'nominee_address' => $nominee?->address,
                'nominee_phone' => $nominee?->phone,
                'nominee_relation' => $nominee?->relation,
                'nominee_photo_url' => $nominee?->photo ? '/files/'.$nominee->photo : null,
            ],
        ]);
    }

    public function update(Request $request, Member $member, IdentityCheckService $identity, UploadService $uploads, AuditService $audit): RedirectResponse
    {
        $data = $this->validateMember($request, $identity, $member);
        $samity = Samity::findOrFail($data['samity_id']);
        $this->assertBranchVisible($request, $samity->branch_id);

        DB::transaction(function () use ($request, $member, $data, $samity, $uploads) {
            if ($request->hasFile('profile_photo_file')) {
                $data['profile_photo'] = $uploads->store($request->file('profile_photo_file'), 'photo', 'members');
            }
            unset($data['profile_photo_file']);
            $nomineeData = $this->extractNominee($data);
            if ($request->hasFile('nominee_photo_file')) {
                $nomineeData['photo'] = $uploads->store($request->file('nominee_photo_file'), 'photo', 'nominees');
            }
            $data['branch_id'] = $samity->branch_id;
            $data['updated_by'] = $request->user()->id;
            $member->update($data);
            if (! empty($nomineeData['name'])) {
                $nominee = $member->nominees->first();
                if ($nominee) {
                    $nominee->update($nomineeData);
                } else {
                    Nominee::create([...$nomineeData, 'member_id' => $member->id]);
                }
            }
        });
        $audit->log($request->user()->id, 'member.updated', 'members', $member->id, null, ['member_no' => $member->member_no]);

        return redirect()->route('members.show', $member)->with('success', __('saved'));
    }

    public function storeDocument(Request $request, Member $member, UploadService $uploads, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'doc_type' => ['required', 'in:'.implode(',', self::DOC_TYPES)],
            'document' => ['required', 'file', 'max:5120'],
        ]);
        $path = $uploads->store($request->file('document'), 'document', 'member-documents');
        $doc = MemberDocument::create([
            'member_id' => $member->id, 'doc_type' => $data['doc_type'],
            'file_path' => $path, 'created_by' => $request->user()->id,
        ]);
        $audit->log($request->user()->id, 'member.document_added', 'member_documents', $doc->id, null, ['member_id' => $member->id, 'doc_type' => $doc->doc_type]);

        return back()->with('success', __('saved'));
    }

    public function storeVisit(Request $request, Member $member, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'visit_date' => ['required', 'date'],
            'info' => ['nullable', 'string'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        $visit = MemberVisit::create([
            ...$data, 'member_id' => $member->id, 'created_by' => $request->user()->id,
        ]);
        $audit->log($request->user()->id, 'member.visit_added', 'member_visits', $visit->id, null, ['member_id' => $member->id]);

        return back()->with('success', __('saved'));
    }

    public function checkIn(Request $request, Member $member, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        $visit = MemberVisit::create([
            'member_id' => $member->id,
            'visit_date' => $this->businessDate($request),
            'info' => 'Check In',
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'check_in_at' => now(),
            'created_by' => $request->user()->id,
        ]);
        $audit->log($request->user()->id, 'member.checked_in', 'member_visits', $visit->id, null, ['member_id' => $member->id]);

        return back()->with('success', __('saved'));
    }

    public function updateLocation(Request $request, Member $member, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);
        $visit = MemberVisit::create([
            'member_id' => $member->id,
            'visit_date' => $this->businessDate($request),
            'info' => 'Location Updated',
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'check_in_at' => now(),
            'created_by' => $request->user()->id,
        ]);
        $audit->log($request->user()->id, 'member.location_updated', 'member_visits', $visit->id, null, ['member_id' => $member->id]);

        return back()->with('success', __('saved'));
    }

    /** Printable admission form (plan: "Admission Form (print)"). */
    public function admissionForm(Member $member): Response
    {
        $member->load(['samity', 'branch', 'nominees']);
        $profile = OrganizationProfile::where('is_active', true)->first();

        return Inertia::render('Members/AdmissionForm', [
            'member' => [...$member->toArray(), 'photo_url' => $member->profile_photo ? '/files/'.$member->profile_photo : null],
            'samity' => $member->samity?->only(['id', 'samity_code', 'name']),
            'branch' => $member->branch?->only(['id', 'code', 'name']),
            'nominee' => $member->nominees->first()?->toArray(),
            'org' => $profile ? ['name_en' => $profile->name_en, 'name_bn' => $profile->name_bn] : null,
        ]);
    }

    // --- helpers -----------------------------------------------------------

    private function assertBranchVisible(Request $request, int $branchId): void
    {
        $visible = app(BranchAccessService::class)->visibleBranches($request->user())->pluck('id')->all();
        abort_unless(in_array($branchId, $visible, true), 403);
    }

    private function extractNominee(array &$data): array
    {
        $nominee = [
            'name' => $data['nominee_name'] ?? null,
            'dob' => $data['nominee_dob'] ?? null,
            'address' => $data['nominee_address'] ?? null,
            'phone' => $data['nominee_phone'] ?? null,
            'relation' => $data['nominee_relation'] ?? null,
        ];
        unset($data['nominee_name'], $data['nominee_dob'], $data['nominee_address'], $data['nominee_phone'], $data['nominee_relation'], $data['nominee_photo_file']);

        return $nominee;
    }

    private function validateMember(Request $request, IdentityCheckService $identity, ?Member $member = null): array
    {
        $rules = [
            'samity_id' => ['required', 'integer', 'exists:samities,id'],
            'name' => ['required', 'string', 'max:255'],
            'father_name' => ['nullable', 'string', 'max:255'],
            'mother_name' => ['nullable', 'string', 'max:255'],
            'spouse_name' => ['nullable', 'string', 'max:255'],
            'gender' => ['required', 'in:'.implode(',', self::GENDERS)],
            'religion' => ['nullable', 'string', 'max:50'],
            'blood_group' => ['nullable', 'in:'.implode(',', self::BLOOD_GROUPS)],
            'marital_status' => ['nullable', 'in:'.implode(',', self::MARITAL)],
            'birth_date' => ['nullable', 'date'],
            'mobile' => ['required', 'regex:/^01[3-9]\d{8}$/'],
            'emergency_phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'national_id' => ['nullable', 'regex:/^\d{10}$|^\d{13}$|^\d{17}$/'],
            'passport_no' => ['nullable', 'string', 'max:50'],
            'tin' => ['nullable', 'string', 'max:50'],
            'occupation' => ['nullable', 'string', 'max:255'],
            'education' => ['nullable', 'string', 'max:255'],
            'present_address' => ['nullable', 'string'],
            'permanent_address' => ['nullable', 'string'],
            'share_amount' => ['required', 'numeric', 'min:0'],
            'open_date' => ['required', 'date'],
            'profile_photo_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:2048'],
            'nominee_name' => ['nullable', 'string', 'max:255'],
            'nominee_dob' => ['nullable', 'date'],
            'nominee_address' => ['nullable', 'string'],
            'nominee_phone' => ['nullable', 'string', 'max:20'],
            'nominee_relation' => ['nullable', 'string', 'max:50'],
            'nominee_photo_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:2048'],
        ];
        $data = $request->validate($rules, [
            'national_id.regex' => __('invalid_nid_format'),
            'mobile.regex' => __('invalid_mobile_format'),
        ]);

        $exceptTable = $member ? 'members' : null;
        $exceptId = $member?->id;
        if (! empty($data['national_id']) && $identity->nidTaken($data['national_id'], $exceptTable, $exceptId)) {
            throw ValidationException::withMessages(['national_id' => __('duplicate_nid')]);
        }
        if (! empty($data['passport_no']) && $identity->passportTaken($data['passport_no'], $exceptTable, $exceptId)) {
            throw ValidationException::withMessages(['passport_no' => __('duplicate_passport')]);
        }
        if ($identity->mobileTaken($data['mobile'], $exceptTable, $exceptId)) {
            throw ValidationException::withMessages(['mobile' => __('duplicate_mobile')]);
        }

        return $data;
    }
}
