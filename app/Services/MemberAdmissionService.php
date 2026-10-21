<?php

namespace App\Services;

use App\Models\Member;
use App\Models\MemberUser;
use App\Models\Nominee;
use App\Models\Samity;
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
    public function __construct(private NumberingService $numbering) {}

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

            return $member;
        });
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
