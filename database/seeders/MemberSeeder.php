<?php

namespace Database\Seeders;

use App\Models\Member;
use App\Models\MemberUser;
use App\Models\Nominee;
use App\Models\Samity;
use App\Services\NumberingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Step 8 — three starter members in the Talgachi samities. */
class MemberSeeder extends Seeder
{
    public function run(): void
    {
        $numbering = app(NumberingService::class);
        $samity1 = Samity::withoutGlobalScopes()->where('name', 'Talgachi Samity')->first();
        $samity2 = Samity::withoutGlobalScopes()->where('name', 'Talgachi Mohila Samity')->first();
        if (! $samity1) {
            return;
        }

        $make = function (array $attrs, ?array $nominee = null) use ($numbering) {
            $existing = Member::withoutGlobalScopes()->where('mobile', $attrs['mobile'])->first();
            if ($existing) {
                return $existing;
            }
            $attrs['member_no'] = $numbering->nextDisplayId($attrs['branch_id'], 'member');
            $member = Member::create($attrs);
            if ($nominee) {
                Nominee::create([...$nominee, 'member_id' => $member->id]);
            }
            MemberUser::create([
                'member_id' => $member->id,
                'username' => $member->mobile,
                'password' => Hash::make(Str::random(12)),
                'is_active' => false,
                'must_change_password' => true,
            ]);

            return $member;
        };

        $make([
            'branch_id' => $samity1->branch_id, 'samity_id' => $samity1->id,
            'name' => 'Mst. Rokeya Begum', 'father_name' => 'Abdul Malek', 'mother_name' => 'Mst. Amena Khatun',
            'spouse_name' => 'Md. Sirajul Islam', 'gender' => 'Female', 'religion' => 'Islam',
            'blood_group' => 'B+', 'marital_status' => 'Married', 'birth_date' => '1985-06-12',
            'mobile' => '01715550001', 'national_id' => '1985112233', 'occupation' => 'Housewife',
            'present_address' => 'Talgachi, Shahjadpur', 'permanent_address' => 'Talgachi, Shahjadpur',
            'share_amount' => 500, 'open_date' => '2021-03-01', 'status' => 'Active',
        ], ['name' => 'Md. Sirajul Islam', 'relation' => 'Husband', 'phone' => '01715550009', 'dob' => '1980-02-03', 'address' => 'Talgachi, Shahjadpur']);

        $make([
            'branch_id' => $samity1->branch_id, 'samity_id' => $samity1->id,
            'name' => 'Md. Hanif Sheikh', 'father_name' => 'Late Karim Sheikh', 'mother_name' => 'Mst. Rahima Begum',
            'gender' => 'Male', 'religion' => 'Islam', 'blood_group' => 'O+', 'marital_status' => 'Married',
            'birth_date' => '1979-09-25', 'mobile' => '01815550002', 'national_id' => '1979223344',
            'occupation' => 'Farmer', 'present_address' => 'Talgachi, Shahjadpur', 'permanent_address' => 'Talgachi, Shahjadpur',
            'share_amount' => 300, 'open_date' => '2021-03-01', 'status' => 'Active',
        ]);

        if ($samity2) {
            $make([
                'branch_id' => $samity2->branch_id, 'samity_id' => $samity2->id,
                'name' => 'Mst. Fatema Khatun', 'father_name' => 'Md. Nurul Islam', 'mother_name' => 'Mst. Salma Begum',
                'gender' => 'Female', 'religion' => 'Islam', 'blood_group' => 'A+', 'marital_status' => 'Married',
                'birth_date' => '1992-01-17', 'mobile' => '01915550003', 'national_id' => '1992334455',
                'occupation' => 'Tailor', 'present_address' => 'Talgachi, Shahjadpur', 'permanent_address' => 'Talgachi, Shahjadpur',
                'share_amount' => 1000, 'open_date' => '2022-05-10', 'status' => 'Active',
            ]);
        }
    }
}
