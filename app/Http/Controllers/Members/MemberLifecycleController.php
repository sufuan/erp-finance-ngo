<?php

namespace App\Http\Controllers\Members;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberClosing;
use App\Models\MemberTransfer;
use App\Models\Samity;
use App\Models\User;
use App\Services\AuditService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 8 — member transfer & closing. Loan/deposit blocking rules check
 * the account tables as soon as they exist (Steps 10/17); until then the
 * guards pass trivially.
 */
class MemberLifecycleController extends Controller
{
    private const REASONS = ['Voluntary', 'Migration', 'Death', 'Expulsion', 'Other'];

    private function businessDate(Request $request): string
    {
        $date = $request->attributes->get('business_date');

        return $date ? Carbon::parse($date)->toDateString() : now()->toDateString();
    }

    private function memberOptions(Request $request): array
    {
        $active = $request->attributes->get('active_branch');

        return Member::with('samity')
            ->when($active, fn ($q) => $q->where('members.branch_id', $active->id))
            ->orderBy('member_no')
            ->get()
            ->map(fn (Member $m) => [
                'id' => $m->id, 'label' => "{$m->member_no} — {$m->name}",
                'samity_id' => $m->samity_id, 'branch_id' => $m->branch_id, 'status' => $m->status,
            ])->all();
    }

    private function samityOptions(): array
    {
        return Samity::orderBy('samity_code')->get()->map(fn (Samity $s) => [
            'id' => $s->id, 'name' => "{$s->samity_code} — {$s->name}", 'branch_id' => $s->branch_id,
        ])->all();
    }

    /** Active or overdue loan → transfers/closings need manager approval (or are blocked). */
    private function memberHasProblemLoan(int $memberId): bool
    {
        if (! Schema::hasTable('loan_accounts')) {
            return false;
        }

        return DB::table('loan_accounts')
            ->where('member_id', $memberId)
            ->whereIn('status', ['Active', 'Overdue'])
            ->exists();
    }

    private function memberHasDepositBalance(int $memberId): bool
    {
        if (! Schema::hasTable('deposit_accounts') || ! Schema::hasColumn('deposit_accounts', 'balance')) {
            return false;
        }

        return DB::table('deposit_accounts')
            ->where('member_id', $memberId)
            ->where('balance', '>', 0)
            ->exists();
    }

    // --- Transfer ----------------------------------------------------------

    public function transferForm(Request $request): Response
    {
        return Inertia::render('Members/Transfer', [
            'members' => $this->memberOptions($request),
            'samities' => $this->samityOptions(),
            'businessDate' => $this->businessDate($request),
            'transfers' => MemberTransfer::with('member')->orderByDesc('id')->limit(50)->get()
                ->map(fn (MemberTransfer $t) => [
                    ...$t->toArray(),
                    'member' => $t->member?->only(['id', 'member_no', 'name']),
                ])->all(),
        ]);
    }

    public function transfer(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'to_samity_id' => ['required', 'integer', 'exists:samities,id'],
            'transfer_date' => ['required', 'date'],
            'reason' => ['nullable', 'string'],
            'manager_approved' => ['nullable', 'boolean'],
        ]);
        $member = Member::findOrFail($data['member_id']);
        $target = Samity::findOrFail($data['to_samity_id']);
        if ((int) $data['to_samity_id'] === $member->samity_id) {
            throw ValidationException::withMessages(['to_samity_id' => __('transfer_same_samity')]);
        }
        if ($this->memberHasProblemLoan($member->id) && empty($data['manager_approved'])) {
            throw ValidationException::withMessages(['manager_approved' => __('transfer_blocked_active_loan')]);
        }

        DB::transaction(function () use ($request, $data, $member, $target, $audit) {
            $fromSamity = $member->samity_id;
            MemberTransfer::create([
                'member_id' => $member->id,
                'from_samity_id' => $fromSamity,
                'to_samity_id' => $target->id,
                'from_branch_id' => $member->branch_id,
                'to_branch_id' => $target->branch_id,
                'transfer_date' => $data['transfer_date'],
                'reason' => $data['reason'] ?? null,
                'created_by' => $request->user()->id,
            ]);
            $member->update(['samity_id' => $target->id, 'branch_id' => $target->branch_id, 'updated_by' => $request->user()->id]);
            $audit->log($request->user()->id, 'member.transferred', 'members', $member->id,
                ['samity_id' => $fromSamity], ['samity_id' => $target->id]);
        });

        return back()->with('success', __('saved'));
    }

    // --- Closing -----------------------------------------------------------

    public function closingForm(Request $request): Response
    {
        return Inertia::render('Members/Closing', [
            'members' => $this->memberOptions($request),
            'reasons' => self::REASONS,
            'businessDate' => $this->businessDate($request),
            'closings' => MemberClosing::with('member')->orderByDesc('id')->limit(100)->get()
                ->map(function (MemberClosing $c) {
                    $closer = $c->closed_by ? User::find($c->closed_by) : null;
                    $canceller = $c->cancelled_by ? User::find($c->cancelled_by) : null;

                    return [
                        ...$c->toArray(),
                        'member' => $c->member?->only(['id', 'member_no', 'name']),
                        'closed_by_name' => $closer?->name,
                        'cancelled_by_name' => $canceller?->name,
                    ];
                })->all(),
        ]);
    }

    public function close(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'closing_date' => ['required', 'date'],
            'closing_reason' => ['required', 'in:'.implode(',', self::REASONS)],
            'share_disposition' => ['required', 'in:Refunded,Forfeited'],
        ]);
        $member = Member::findOrFail($data['member_id']);
        if ($member->status === 'Closed') {
            throw ValidationException::withMessages(['member_id' => __('member_already_closed')]);
        }
        if ($this->memberHasProblemLoan($member->id)) {
            throw ValidationException::withMessages(['member_id' => __('closing_blocked_active_loan')]);
        }
        if ($this->memberHasDepositBalance($member->id)) {
            throw ValidationException::withMessages(['member_id' => __('closing_blocked_savings_balance')]);
        }

        DB::transaction(function () use ($request, $data, $member, $audit) {
            $closing = MemberClosing::create([
                'member_id' => $member->id,
                'closing_date' => $data['closing_date'],
                'closing_reason' => $data['closing_reason'],
                'share_amount_at_closing' => $member->share_amount,
                'share_disposition' => $data['share_disposition'],
                'closed_by' => $request->user()->id,
                'closed_at' => now(),
            ]);
            $member->update(['status' => 'Closed', 'updated_by' => $request->user()->id]);
            $member->memberUser?->update(['is_active' => false]);
            $audit->log($request->user()->id, 'member.closed', 'member_closings', $closing->id, null,
                ['member_id' => $member->id, 'reason' => $data['closing_reason'], 'share_disposition' => $data['share_disposition']]);
        });

        return back()->with('success', __('saved'));
    }

    public function cancelClosing(Request $request, MemberClosing $closing, AuditService $audit): RedirectResponse
    {
        if ($closing->cancelled_at) {
            return back()->with('error', __('closing_already_cancelled'));
        }
        DB::transaction(function () use ($request, $closing, $audit) {
            $closing->update(['cancelled_by' => $request->user()->id, 'cancelled_at' => now()]);
            $closing->member->update(['status' => 'Active', 'updated_by' => $request->user()->id]);
            $audit->log($request->user()->id, 'member.closing_cancelled', 'member_closings', $closing->id, null, ['member_id' => $closing->member_id]);
        });

        return back()->with('success', __('saved'));
    }
}
