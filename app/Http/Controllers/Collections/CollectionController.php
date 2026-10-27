<?php

namespace App\Http\Controllers\Collections;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CollectionBatch;
use App\Models\CollectionLine;
use App\Models\Member;
use App\Models\OtherCollection;
use App\Models\Samity;
use App\Services\AuditService;
use App\Services\BranchAccessService;
use App\Services\BusinessDayService;
use App\Services\CollectionService;
use App\Services\SamityAccessService;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 21 — collection posting pages: Member Collection, Sheet Posting,
 * Simple Sheet Posting, the two collection lists, and Other Collection
 * Receive. All money fans out through CollectionService → the deposit /
 * loan engines; posting always lands on the branch's OPEN business day.
 */
class CollectionController extends Controller
{
    public function __construct(
        private CollectionService $service,
        private BranchAccessService $branches,
        private SamityAccessService $samityAccess,
        private BusinessDayService $businessDays,
    ) {}

    // --- shared lookups ----------------------------------------------------

    /** Samities the user may collect for (active branch first choice). */
    private function samities(Request $request)
    {
        $branchIds = $this->branches->accessibleIds($request->user());
        $active = $request->attributes->get('active_branch');
        $query = Samity::whereIn('branch_id', $branchIds)->where('status', 'Active')->orderBy('name');
        if ($active) {
            $query->where('branch_id', $active->id);
        }
        if ($this->samityAccess->isRestricted($request->user())) {
            $query->whereIn('id', $this->samityAccess->assignedSamityIds($request->user()));
        }

        return $query->get(['id', 'samity_code', 'name', 'branch_id'])
            ->map(fn (Samity $s) => ['id' => $s->id, 'code' => $s->samity_code, 'name' => $s->name, 'branch_id' => $s->branch_id])
            ->all();
    }

    private function samityOptions(Request $request): array
    {
        return ['samities' => $this->samities($request)];
    }

    private function findSamity(Request $request): ?Samity
    {
        $id = $request->integer('samity_id');
        if (! $id) {
            return null;
        }
        $samity = Samity::find($id);
        if (! $samity) {
            return null;
        }
        abort_unless($this->branches->canAccess($request->user(), $samity->branch_id), 403);
        $this->samityAccess->assertAccess($request->user(), $samity->id);

        return $samity;
    }

    private function findMember(Request $request, string $param = 'member'): ?Member
    {
        $needle = trim((string) $request->query($param, ''));
        if ($needle === '') {
            return null;
        }
        $member = Member::with('samity')->where('member_no', $needle)
            ->orWhere('id', ctype_digit($needle) ? (int) $needle : -1)->first();
        if ($member) {
            abort_unless($this->branches->canAccess($request->user(), $member->branch_id), 403);
        }

        return $member;
    }

    private function businessDateFor(?int $branchId, Request $request): ?string
    {
        $branchId ??= $request->attributes->get('active_branch')?->id
            ?? Branch::where('type', 'Head Office')->value('id');
        if (! $branchId) {
            return null;
        }
        try {
            return $this->businessDays->currentDate((int) $branchId);
        } catch (\Throwable) {
            return null;
        }
    }

    private function linesFromRequest(Request $request): array
    {
        $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.member_id' => ['required', 'integer'],
            'lines.*.account_type' => ['required', 'in:deposit,loan,share'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.amount' => ['required', 'numeric', 'min:0'],
        ]);

        return collect($request->input('lines'))->map(fn ($l) => [
            'member_id' => (int) $l['member_id'],
            'account_type' => $l['account_type'],
            'account_id' => (int) $l['account_id'],
            'amount' => (string) $l['amount'],
        ])->all();
    }

    // --- Member Collection ---------------------------------------------------

    public function memberForm(Request $request): Response
    {
        $member = $this->findMember($request);

        return Inertia::render('Collections/MemberCollection', [
            ...$this->samityOptions($request),
            'member' => $member ? ['id' => $member->id, 'member_no' => $member->member_no, 'name' => $member->name] : null,
            'memberQuery' => (string) $request->query('member', ''),
            'grid' => $member ? $this->service->gridForMember($member) : [],
            'businessDate' => $this->businessDateFor($member?->branch_id, $request),
        ]);
    }

    public function storeMember(Request $request, AuditService $audit): RedirectResponse
    {
        $request->validate(['member_id' => ['required', 'integer']]);
        $member = Member::findOrFail($request->integer('member_id'));
        abort_unless($this->branches->canAccess($request->user(), $member->branch_id), 403);
        $batch = $this->service->postBatch($request->user(), [
            'kind' => 'member', 'member_id' => $member->id,
            'lines' => $this->linesFromRequest($request),
        ]);
        $audit->log($request->user()->id, 'collection.posted', 'collection_batches', $batch->id, null, ['total' => (string) $batch->total_receive]);

        return back()->with('success', __('collection_posted'));
    }

    // --- Sheet Posting / Simple Sheet Posting --------------------------------

    public function sheetForm(Request $request): Response
    {
        $samity = $this->findSamity($request);

        return Inertia::render('Collections/SheetPosting', [
            ...$this->samityOptions($request),
            'samityId' => $samity?->id,
            'grid' => $samity ? $this->service->gridForSamity($samity) : [],
            'businessDate' => $this->businessDateFor($samity?->branch_id, $request),
        ]);
    }

    public function storeSheet(Request $request, AuditService $audit): RedirectResponse
    {
        $samity = $this->findSamity($request);
        abort_unless($samity !== null, 422, __('collection_target_required'));
        $batch = $this->service->postBatch($request->user(), [
            'kind' => 'sheet', 'samity_id' => $samity->id,
            'lines' => $this->linesFromRequest($request),
        ]);
        $audit->log($request->user()->id, 'collection.posted', 'collection_batches', $batch->id, null, ['total' => (string) $batch->total_receive]);

        return back()->with('success', __('collection_posted'));
    }

    public function simpleForm(Request $request): Response
    {
        $samity = $this->findSamity($request);
        $member = $this->findMember($request);
        $grid = [];
        if ($samity) {
            $grid = $this->service->gridForSamity($samity);
            if ($member) {
                $grid = array_values(array_filter($grid, fn ($row) => (int) $row['member_id'] === $member->id));
            }
        }

        return Inertia::render('Collections/SimpleSheetPosting', [
            ...$this->samityOptions($request),
            'samityId' => $samity?->id,
            'memberQuery' => (string) $request->query('member', ''),
            'grid' => $grid,
            'businessDate' => $this->businessDateFor($samity?->branch_id, $request),
        ]);
    }

    public function storeSimple(Request $request, AuditService $audit): RedirectResponse
    {
        $samity = $this->findSamity($request);
        abort_unless($samity !== null, 422, __('collection_target_required'));
        $batch = $this->service->postBatch($request->user(), [
            'kind' => 'simple', 'samity_id' => $samity->id,
            'lines' => $this->linesFromRequest($request),
        ]);
        $audit->log($request->user()->id, 'collection.posted', 'collection_batches', $batch->id, null, ['total' => (string) $batch->total_receive]);

        return back()->with('success', __('collection_posted'));
    }

    // --- Collection lists ------------------------------------------------------

    public function memberList(Request $request): Response
    {
        $branchIds = $this->branches->accessibleIds($request->user());
        $from = $request->query('from');
        $upto = $request->query('upto');
        $memberNeedle = trim((string) $request->query('member', ''));

        $rows = DB::table('collection_lines as l')
            ->join('collection_batches as b', 'b.id', '=', 'l.batch_id')
            ->join('members as m', 'm.id', '=', 'l.member_id')
            ->leftJoin('samities as s', 's.id', '=', 'b.samity_id')
            ->leftJoin('users as u', 'u.id', '=', 'b.entry_by')
            ->whereIn('b.branch_id', $branchIds)
            ->when($from, fn ($q) => $q->where('b.business_date', '>=', $from))
            ->when($upto, fn ($q) => $q->where('b.business_date', '<=', $upto))
            ->when($memberNeedle !== '', fn ($q) => $q->where('m.member_no', $memberNeedle))
            ->groupBy('l.batch_id', 'l.member_id', 'b.business_date', 'b.tran_date', 'm.member_no', 'm.name', 's.name', 'u.name', 'b.status')
            ->select([
                'l.batch_id', 'l.member_id', 'b.business_date', 'b.tran_date', 'b.status as batch_status',
                'm.member_no', 'm.name as member_name', 's.name as samity_name', 'u.name as entry_by_name',
                DB::raw("SUM(CASE WHEN l.account_type='deposit' AND l.status='Posted' THEN l.amount ELSE 0 END) as deposit_amount"),
                DB::raw("SUM(CASE WHEN l.account_type='loan' AND l.status='Posted' THEN l.amount ELSE 0 END) as loan_amount"),
                DB::raw("SUM(CASE WHEN l.account_type='share' AND l.status='Posted' THEN l.amount ELSE 0 END) as share_amount"),
                DB::raw("SUM(CASE WHEN l.status='Posted' THEN l.amount ELSE 0 END) as total_receive"),
                DB::raw('MAX(l.cancel_at) as cancel_at'),
            ])
            ->orderByDesc('b.business_date')->orderByDesc('l.batch_id')
            ->limit(500)->get()
            ->map(fn ($r) => [
                'batch_id' => $r->batch_id, 'member_id' => $r->member_id,
                'date' => $r->business_date, 'member_no' => $r->member_no,
                'member_name' => $r->member_name, 'samity_name' => $r->samity_name,
                'deposit_amount' => (string) $r->deposit_amount, 'loan_amount' => (string) $r->loan_amount,
                'share_amount' => (string) $r->share_amount, 'total_receive' => (string) $r->total_receive,
                'entry_by' => $r->entry_by_name, 'cancel_at' => $r->cancel_at,
                'cancelled' => (float) $r->total_receive <= 0,
            ])->all();

        return Inertia::render('Collections/MemberCollectionList', [
            'rows' => $rows,
            'filters' => ['from' => $from, 'upto' => $upto, 'member' => $memberNeedle],
        ]);
    }

    public function samityList(Request $request): Response
    {
        $branchIds = $this->branches->accessibleIds($request->user());
        $from = $request->query('from');
        $upto = $request->query('upto');
        $samityId = $request->integer('samity_id') ?: null;

        $batches = CollectionBatch::with(['samity:id,samity_code,name', 'entryBy:id,name'])
            ->whereIn('branch_id', $branchIds)->whereNotNull('samity_id')
            ->when($from, fn ($q) => $q->where('business_date', '>=', $from))
            ->when($upto, fn ($q) => $q->where('business_date', '<=', $upto))
            ->when($samityId, fn ($q) => $q->where('samity_id', $samityId))
            ->orderByDesc('business_date')->orderByDesc('id')->limit(500)->get()
            ->map(fn (CollectionBatch $b) => [
                'id' => $b->id, 'date' => $b->business_date?->toDateString(),
                'samity' => $b->samity ? $b->samity->samity_code.' — '.$b->samity->name : null,
                'kind' => $b->kind,
                'deposit_amount' => (string) $b->deposit_receive, 'loan_amount' => (string) $b->loan_receive,
                'share_amount' => (string) $b->share_receive, 'total_receive' => (string) $b->total_receive,
                'total_dues' => (string) $b->total_dues, 'status' => $b->status,
                'entry_by' => $b->entryBy?->name,
                'cancel_by' => $b->cancel_by, 'cancel_at' => $b->cancel_at?->toDateTimeString(),
            ])->all();

        return Inertia::render('Collections/SamityCollectionList', [
            ...$this->samityOptions($request),
            'rows' => $batches,
            'filters' => ['from' => $from, 'upto' => $upto, 'samity_id' => $samityId],
        ]);
    }

    // --- Cancels -----------------------------------------------------------------

    public function cancelBatch(Request $request, CollectionBatch $batch, AuditService $audit): RedirectResponse
    {
        abort_unless($this->branches->canAccess($request->user(), $batch->branch_id), 403);
        $reason = (string) $request->input('reason', 'Cancelled from collection list');
        $this->service->cancelBatch($batch, $request->user(), $reason);
        $audit->log($request->user()->id, 'collection.cancelled', 'collection_batches', $batch->id, null, ['reason' => $reason]);

        return back()->with('success', __('collection_cancelled'));
    }

    public function cancelMemberLines(Request $request, CollectionBatch $batch, AuditService $audit): RedirectResponse
    {
        abort_unless($this->branches->canAccess($request->user(), $batch->branch_id), 403);
        $request->validate(['member_id' => ['required', 'integer']]);
        $reason = (string) $request->input('reason', 'Cancelled from collection list');
        $this->service->cancelMemberLines($batch, $request->integer('member_id'), $request->user(), $reason);
        $audit->log($request->user()->id, 'collection.cancelled', 'collection_batches', $batch->id, null, ['member_id' => $request->integer('member_id'), 'reason' => $reason]);

        return back()->with('success', __('collection_cancelled'));
    }

    public function cancelLine(Request $request, CollectionLine $line, AuditService $audit): RedirectResponse
    {
        abort_unless($this->branches->canAccess($request->user(), $line->batch->branch_id), 403);
        $reason = (string) $request->input('reason', 'Cancelled from collection list');
        $this->service->cancelLine($line, $request->user(), $reason);
        $audit->log($request->user()->id, 'collection.cancelled', 'collection_lines', $line->id, null, ['reason' => $reason]);

        return back()->with('success', __('collection_cancelled'));
    }

    // --- Other Collection Receive ---------------------------------------------------

    public function otherForm(Request $request, SettingsService $settings): Response
    {
        $branchIds = $this->branches->accessibleIds($request->user());
        $active = $request->attributes->get('active_branch');
        $rows = OtherCollection::with(['member:id,member_no,name', 'branch:id,code,name'])
            ->whereIn('branch_id', $branchIds)
            ->when($request->query('type'), fn ($q, $t) => $q->where('collection_type', $t))
            ->when($request->query('from'), fn ($q, $f) => $q->where('business_date', '>=', $f))
            ->when($request->query('upto'), fn ($q, $u) => $q->where('business_date', '<=', $u))
            ->orderByDesc('id')->limit(200)->get()
            ->map(fn (OtherCollection $o) => [
                'id' => $o->id, 'date' => $o->business_date?->toDateString(),
                'type' => $o->collection_type, 'account' => $o->collection_account,
                'member' => $o->member ? $o->member->member_no.' — '.$o->member->name : null,
                'amount' => (string) $o->amount, 'document_number' => $o->document_number,
                'comments' => $o->comments, 'cancel_at' => $o->cancel_at?->toDateTimeString(),
            ])->all();

        $types = $settings->get('collection.other_types') ?: ['Miscellaneous'];
        $balances = [];
        foreach (OtherCollection::whereIn('branch_id', $branchIds)->whereNull('cancel_at')->get(['collection_type', 'amount']) as $o) {
            $balances[$o->collection_type] = number_format((float) ($balances[$o->collection_type] ?? 0) + (float) $o->amount, 2, '.', '');
        }

        return Inertia::render('Collections/OtherCollection', [
            'types' => is_array($types) ? array_values($types) : ['Miscellaneous'],
            'balances' => $balances,
            'rows' => $rows,
            'branchId' => $active?->id,
            'businessDate' => $this->businessDateFor($active?->id, $request),
            'filters' => ['type' => $request->query('type'), 'from' => $request->query('from'), 'upto' => $request->query('upto')],
        ]);
    }

    public function storeOther(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'collection_type' => ['required', 'string', 'max:100'],
            'collection_account' => ['nullable', 'string', 'max:150'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'member' => ['nullable', 'string'],
            'document_number' => ['nullable', 'string', 'max:100'],
            'comments' => ['nullable', 'string', 'max:500'],
        ]);
        $active = $request->attributes->get('active_branch');
        abort_unless($active !== null, 422, __('no_active_branch'));
        $memberId = null;
        if (! empty($data['member'])) {
            $member = Member::where('member_no', trim($data['member']))->first();
            if ($member) {
                abort_unless($this->branches->canAccess($request->user(), $member->branch_id), 403);
                $memberId = $member->id;
            }
        }
        $other = $this->service->postOther($request->user(), [
            'branch_id' => $active->id, 'member_id' => $memberId,
            'collection_type' => $data['collection_type'],
            'collection_account' => $data['collection_account'] ?? null,
            'amount' => (string) $data['amount'],
            'document_number' => $data['document_number'] ?? null,
            'comments' => $data['comments'] ?? null,
        ]);
        $audit->log($request->user()->id, 'collection.other_posted', 'other_collections', $other->id, null, ['amount' => (string) $other->amount]);

        return back()->with('success', __('collection_posted'));
    }

    public function cancelOther(Request $request, OtherCollection $other, AuditService $audit): RedirectResponse
    {
        abort_unless($this->branches->canAccess($request->user(), $other->branch_id), 403);
        $reason = (string) $request->input('reason', 'Cancelled from other collection list');
        $this->service->cancelOther($other, $request->user(), $reason);
        $audit->log($request->user()->id, 'collection.other_cancelled', 'other_collections', $other->id, null, ['reason' => $reason]);

        return back()->with('success', __('collection_cancelled'));
    }
}
