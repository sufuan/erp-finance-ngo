<?php

namespace App\Services;

use App\Models\Member;
use App\Models\MemberUser;
use App\Models\Nominee;
use App\Models\Samity;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Single member-admission path used by the Step 8 admission form and the
 * Step 9A migration import: creates the member, its nominee (when named)
 * and the inactive portal user — always inside one transaction so a
 * member never exists without its login.
 */
class MemberAdmissionService
{
    public function __construct(
        private NumberingService $numbering,
        private PostingService $posting,
    ) {}

    /**
     * @param  array  $data  Member fields; may carry nominee_* keys (plus a
     *                       nominee_photo path) and a member_no override for
     *                       migrated members whose legacy ID is kept.
     */
    public function admit(array $data, Samity $samity, ?int $actorId = null): Member
    {
        return DB::transaction(function () use ($data, $samity, $actorId) {
            $nominee = [
                'name' => $data['nominee_name'] ?? null,
                'dob' => $data['nominee_dob'] ?? null,
                'address' => $data['nominee_address'] ?? null,
                'phone' => $data['nominee_phone'] ?? null,
                'relation' => $data['nominee_relation'] ?? null,
                'photo' => $data['nominee_photo'] ?? null,
            ];
            unset(
                $data['nominee_name'], $data['nominee_dob'], $data['nominee_address'],
                $data['nominee_phone'], $data['nominee_relation'], $data['nominee_photo']
            );

            $data['branch_id'] = $samity->branch_id;
            $data['samity_id'] = $samity->id;
            $data['member_no'] = $data['member_no'] ?? $this->numbering->nextDisplayId($samity->branch_id, 'member');
            $data['status'] = $data['status'] ?? 'Active';
            $data['created_by'] = $actorId;
            $data['updated_by'] = $actorId;

            $member = Member::create($data);
            if (! empty($nominee['name'])) {
                Nominee::create([...$nominee, 'member_id' => $member->id]);
            }
            $this->createPortalUser($member);
            $this->postAdmissionShare($member, $actorId);

            return $member;
        });
    }

    /**
     * The admission share is real money received over the counter, so it
     * must exist in the share sub-ledger from day one — posted exactly
     * like a share-desk receipt. Without this row the ledger starts at
     * zero and DepositTransactionService::resyncShare() silently wipes
     * the admission amount off members.share_amount on the member's
     * first share transaction.
     */
    private function postAdmissionShare(Member $member, ?int $actorId): void
    {
        $share = (string) ($member->share_amount ?? '0');
        if ($actorId === null || ! is_numeric($share) || ! BigDecimal::of($share)->isPositive()) {
            return;
        }

        $this->posting->post([
            'branch_id' => $member->branch_id,
            'voucher_type' => 'Receipt',
            'entry_by' => $actorId,
            'comments' => 'Share received at admission',
            'lines' => [['mapping_key' => 'share.receive', 'amount' => $share, 'document_number' => null]],
            'movements' => [
                ['account_type' => 'share', 'account_id' => $member->id, 'tran_code' => 'SHARE_RECEIVE', 'amount' => $share, 'ref_type' => 'member', 'ref_id' => $member->id],
                ['account_type' => 'cash', 'account_id' => $actorId, 'tran_code' => 'CASH_IN', 'amount' => $share],
            ],
        ]);
    }

    /** Portal login: username = mobile (fallback M<member_no>), inactive. */
    public function createPortalUser(Member $member): MemberUser
    {
        $username = $member->mobile;
        if (! $username || MemberUser::where('username', $username)->exists()) {
            $username = 'M'.$member->member_no;
        }

        return MemberUser::create([
            'member_id' => $member->id,
            'username' => $username,
            'password' => Hash::make(Str::random(12)),
            'is_active' => false,
            'must_change_password' => true,
        ]);
    }
}
