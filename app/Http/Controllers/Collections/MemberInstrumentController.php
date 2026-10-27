<?php

namespace App\Http\Controllers\Collections;

use App\Http\Controllers\Controller;
use App\Models\ChequeBookIssue;
use App\Models\DepositAccount;
use App\Models\DepositTransaction;
use App\Models\LoanReceive;
use App\Models\Member;
use App\Models\OrganizationProfile;
use App\Models\PassbookIssue;
use App\Services\AuditService;
use App\Services\BranchAccessService;
use App\Services\BusinessDayService;
use App\Services\CollectionService;
use App\Services\PdfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 23 — Passbook Issue, Cheque Book Issue, and the printable
 * Member Passbook (merged deposit + loan statement for one member).
 * Fees post through CollectionService → PostingService.
 */
class MemberInstrumentController extends Controller
{
    public function __construct(
        private CollectionService $service,
        private BranchAccessService $branches,
        private BusinessDayService $businessDays,
    ) {}

    private function findMember(Request $request): ?Member
    {
        $needle = trim((string) $request->query('member', ''));
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

    private function memberPayload(?Member $member): ?array
    {
        return $member ? [
            'id' => $member->id, 'member_no' => $member->member_no, 'name' => $member->name,
            'samity' => $member->samity?->name, 'mobile' => $member->mobile,
            'share_amount' => (string) $member->share_amount,
        ] : null;
    }

    // --- Passbook Issue -------------------------------------------------------

    public function passbookForm(Request $request): Response
    {
        $member = $this->findMember($request);
        $rows = PassbookIssue::with('member:id,member_no,name')
            ->whereIn('branch_id', $this->branches->accessibleIds($request->user()))
            ->when($member, fn ($q) => $q->where('member_id', $member->id))
            ->orderByDesc('id')->limit(200)->get()
            ->map(fn (PassbookIssue $p) => [
                'id' => $p->id, 'member_no' => $p->member->member_no, 'member_name' => $p->member->name,
                'book_number' => $p->book_number, 'fee_amount' => (string) $p->fee_amount,
                'issue_date' => $p->issue_date?->toDateString(), 'document_number' => $p->document_number,
                'cancel_at' => $p->cancel_at?->toDateTimeString(),
            ])->all();
        $lastNumber = PassbookIssue::whereIn('branch_id', $this->branches->accessibleIds($request->user()))
            ->orderByDesc('id')->value('book_number');

        return Inertia::render('Collections/PassbookIssue', [
            'member' => $this->memberPayload($member),
            'memberQuery' => (string) $request->query('member', ''),
            'rows' => $rows,
            'nextBookNumber' => $lastNumber ? (string) ((int) preg_replace('/\D/', '', $lastNumber) + 1) : '1',
            'businessDate' => $this->businessDate($request),
        ]);
    }

    public function storePassbook(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'member_id' => ['required', 'integer'],
            'book_number' => ['required', 'string', 'max:50'],
            'fee_amount' => ['nullable', 'numeric', 'min:0'],
            'document_number' => ['nullable', 'string', 'max:100'],
        ]);
        $member = Member::findOrFail($data['member_id']);
        abort_unless($this->branches->canAccess($request->user(), $member->branch_id), 403);
        $issue = $this->service->issuePassbook($request->user(), $data);
        $audit->log($request->user()->id, 'collection.passbook_issued', 'passbook_issues', $issue->id, null, ['book_number' => $issue->book_number]);

        return back()->with('success', __('passbook_issued'));
    }

    public function cancelPassbook(Request $request, PassbookIssue $issue, AuditService $audit): RedirectResponse
    {
        abort_unless($this->branches->canAccess($request->user(), $issue->branch_id), 403);
        $this->service->cancelPassbook($issue, $request->user(), (string) $request->input('reason', 'Cancelled'));
        $audit->log($request->user()->id, 'collection.passbook_cancelled', 'passbook_issues', $issue->id);

        return back()->with('success', __('cancelled_successfully'));
    }

    // --- Cheque Book Issue ------------------------------------------------------

    public function chequeForm(Request $request): Response
    {
        $member = $this->findMember($request);
        $rows = ChequeBookIssue::with('member:id,member_no,name')
            ->whereIn('branch_id', $this->branches->accessibleIds($request->user()))
            ->when($member, fn ($q) => $q->where('member_id', $member->id))
            ->orderByDesc('id')->limit(200)->get()
            ->map(fn (ChequeBookIssue $c) => [
                'id' => $c->id, 'member_no' => $c->member->member_no, 'member_name' => $c->member->name,
                'cheque_prefix' => $c->cheque_prefix, 'start_leaf' => $c->start_leaf, 'end_leaf' => $c->end_leaf,
                'fee_amount' => (string) $c->fee_amount, 'issue_date' => $c->issue_date?->toDateString(),
                'document_number' => $c->document_number, 'cancel_at' => $c->cancel_at?->toDateTimeString(),
            ])->all();

        return Inertia::render('Collections/ChequeBookIssue', [
            'member' => $this->memberPayload($member),
            'memberQuery' => (string) $request->query('member', ''),
            'rows' => $rows,
            'businessDate' => $this->businessDate($request),
        ]);
    }

    public function storeCheque(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'member_id' => ['required', 'integer'],
            'cheque_prefix' => ['nullable', 'string', 'max:20'],
            'start_leaf' => ['required', 'string', 'max:30'],
            'end_leaf' => ['required', 'string', 'max:30'],
            'fee_amount' => ['nullable', 'numeric', 'min:0'],
            'document_number' => ['nullable', 'string', 'max:100'],
        ]);
        $member = Member::findOrFail($data['member_id']);
        abort_unless($this->branches->canAccess($request->user(), $member->branch_id), 403);
        $issue = $this->service->issueChequeBook($request->user(), $data);
        $audit->log($request->user()->id, 'collection.cheque_issued', 'cheque_book_issues', $issue->id, null, ['start_leaf' => $issue->start_leaf]);

        return back()->with('success', __('cheque_book_issued'));
    }

    public function cancelCheque(Request $request, ChequeBookIssue $issue, AuditService $audit): RedirectResponse
    {
        abort_unless($this->branches->canAccess($request->user(), $issue->branch_id), 403);
        $this->service->cancelChequeBook($issue, $request->user(), (string) $request->input('reason', 'Cancelled'));
        $audit->log($request->user()->id, 'collection.cheque_cancelled', 'cheque_book_issues', $issue->id);

        return back()->with('success', __('cancelled_successfully'));
    }

    // --- Member Passbook ---------------------------------------------------------

    /** Merged statement rows (deposits + loan receipts) for one member. */
    private function passbookRows(Member $member, Request $request): array
    {
        $from = $request->query('from');
        $upto = $request->query('upto');
        $allDates = $request->boolean('all_dates', true);
        $status = $request->query('account_status', 'all');

        $accounts = DepositAccount::with('product:id,code')
            ->where('member_id', $member->id)
            ->when($status === 'active', fn ($q) => $q->where('is_active', true)->whereNull('closing_date'))
            ->when($status === 'closed', fn ($q) => $q->whereNotNull('closing_date'))
            ->when($request->filled('deposit_account_id'), fn ($q) => $q->where('id', $request->integer('deposit_account_id')))
            ->get();
        $loanIds = \App\Models\LoanAccount::where('member_id', $member->id)
            ->when($request->filled('loan_account_id'), fn ($q) => $q->where('id', $request->integer('loan_account_id')))
            ->pluck('id');

        $rows = [];
        $depositTxns = DepositTransaction::whereIn('account_id', $accounts->pluck('id'))
            ->when(! $allDates && $from, fn ($q) => $q->where('business_date', '>=', $from))
            ->when(! $allDates && $upto, fn ($q) => $q->where('business_date', '<=', $upto))
            ->get();
        foreach ($depositTxns as $txn) {
            $account = $accounts->firstWhere('id', $txn->account_id);
            $rows[] = [
                'date' => $txn->business_date?->toDateString() ?? '',
                'particulars' => ($account?->product?->code ?? 'Deposit').' '.$txn->tran_type.($txn->cancel_at ? ' ('.__('cancelled').')' : ''),
                'debit' => $txn->tran_type === 'Payment' && ! $txn->cancel_at ? (string) $txn->amount : '',
                'credit' => $txn->tran_type === 'Receive' && ! $txn->cancel_at ? (string) $txn->amount : '',
                'sort' => $txn->business_date?->toDateString().'-1-'.$txn->id,
            ];
        }
        $receives = LoanReceive::with('loanAccount:id,loan_number')->whereIn('loan_account_id', $loanIds)
            ->when(! $allDates && $from, fn ($q) => $q->where('business_date', '>=', $from))
            ->when(! $allDates && $upto, fn ($q) => $q->where('business_date', '<=', $upto))
            ->get();
        foreach ($receives as $receive) {
            $rows[] = [
                'date' => $receive->business_date?->toDateString() ?? '',
                'particulars' => 'Loan '.($receive->loanAccount?->loan_number ?? '').' Installment'.($receive->cancel_at ? ' ('.__('cancelled').')' : ''),
                'debit' => '',
                'credit' => ! $receive->cancel_at ? (string) $receive->installment_amount : '',
                'sort' => $receive->business_date?->toDateString().'-2-'.$receive->id,
            ];
        }
        usort($rows, fn ($a, $b) => strcmp($a['sort'], $b['sort']));
        $balance = \Brick\Math\BigDecimal::zero();
        foreach ($rows as &$row) {
            if ($row['credit'] !== '') {
                $balance = $balance->plus($row['credit']);
            }
            if ($row['debit'] !== '') {
                $balance = $balance->minus($row['debit']);
            }
            $row['balance'] = (string) $balance->toScale(2, \Brick\Math\RoundingMode::HalfUp);
            unset($row['sort']);
        }

        return [
            'accounts' => $accounts->map(fn ($a) => [
                'id' => $a->id, 'account_number' => $a->account_number,
                'product' => $a->product?->code, 'current_balance' => (string) $a->current_balance,
            ])->all(),
            'loans' => \App\Models\LoanAccount::where('member_id', $member->id)->get(['id', 'loan_number', 'status'])
                ->map(fn ($l) => ['id' => $l->id, 'loan_number' => $l->loan_number, 'status' => $l->status])->all(),
            'rows' => $rows,
        ];
    }

    public function passbook(Request $request): Response
    {
        $member = $this->findMember($request);
        $data = $member ? $this->passbookRows($member, $request) : ['accounts' => [], 'loans' => [], 'rows' => []];

        return Inertia::render('Collections/MemberPassbook', [
            'member' => $this->memberPayload($member),
            'memberQuery' => (string) $request->query('member', ''),
            ...$data,
            'filters' => [
                'from' => $request->query('from'), 'upto' => $request->query('upto'),
                'account_status' => $request->query('account_status', 'all'),
                'deposit_account_id' => $request->integer('deposit_account_id') ?: null,
                'loan_account_id' => $request->integer('loan_account_id') ?: null,
            ],
        ]);
    }

    public function passbookPdf(Request $request, PdfService $pdf): HttpResponse
    {
        $member = $this->findMember($request);
        abort_unless($member !== null, 404);
        $data = $this->passbookRows($member, $request);
        $org = OrganizationProfile::where('is_active', true)->first() ?? OrganizationProfile::first();
        $html = view('reports.generic', [
            'org' => $org,
            'title' => __('member_passbook').' — '.$member->member_no.' '.$member->name,
            'filter_summary' => ($request->query('from') ?: '').' — '.($request->query('upto') ?: ''),
            'columns' => [__('date'), __('particulars'), __('debit'), __('credit'), __('balance')],
            'rows' => array_map(fn ($r) => [$r['date'], $r['particulars'], $r['debit'], $r['credit'], $r['balance']], $data['rows']),
            'total_row' => null,
        ])->render();
        $bytes = $pdf->renderHtmlWithFallback($html, count($data['rows']));

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="passbook-'.$member->member_no.'.pdf"',
        ]);
    }

    private function businessDate(Request $request): ?string
    {
        $active = $request->attributes->get('active_branch');
        if (! $active) {
            return null;
        }
        try {
            return $this->businessDays->currentDate($active->id);
        } catch (\Throwable) {
            return null;
        }
    }
}
