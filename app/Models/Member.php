<?php

namespace App\Models;

use App\Models\Scopes\SamityScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Member (Step 8). share_amount is the canonical share capital — there
 * is no separate SHARE deposit account; Step 11 posts share movements
 * against this column through the PostingService sub-ledger.
 */
#[ScopedBy([SamityScope::class])]
class Member extends Model
{
    protected $fillable = [
        'member_no', 'branch_id', 'samity_id', 'name', 'father_name', 'mother_name',
        'spouse_name', 'gender', 'religion', 'blood_group', 'marital_status',
        'birth_date', 'mobile', 'emergency_phone', 'email', 'national_id', 'nid_hash',
        'passport_no', 'passport_hash', 'tin', 'occupation', 'education',
        'present_address', 'permanent_address', 'share_amount', 'open_date',
        'profile_photo', 'status', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'open_date' => 'date',
            'share_amount' => 'decimal:2',
            'national_id' => 'encrypted',
            'passport_no' => 'encrypted',
            'tin' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Member $member) {
            $member->nid_hash = $member->national_id
                ? hash('sha256', preg_replace('/\D/', '', (string) $member->national_id))
                : null;
            $member->passport_hash = $member->passport_no
                ? hash('sha256', strtoupper(trim((string) $member->passport_no)))
                : null;
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function samity(): BelongsTo
    {
        return $this->belongsTo(Samity::class);
    }

    public function memberUser(): HasOne
    {
        return $this->hasOne(MemberUser::class);
    }

    public function nominees(): HasMany
    {
        return $this->hasMany(Nominee::class);
    }

    public function closings(): HasMany
    {
        return $this->hasMany(MemberClosing::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(MemberVisit::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(MemberDocument::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(MemberTransfer::class);
    }
}
