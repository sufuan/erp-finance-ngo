<?php

namespace Database\Seeders;

use App\Models\OrganizationProfile;
use Illuminate\Database\Seeder;

/** TPDA primary data (architecture §1.3) — the single active profile. */
class OrganizationProfileSeeder extends Seeder
{
    public function run(): void
    {
        OrganizationProfile::updateOrCreate(
            ['registration_no' => 'সিরাজ/৫৮৫/সমাজসেবা/২০০০'],
            [
                'name_en' => 'Talgachi Poor Development Association (T.P.D.A.)',
                'name_bn' => 'তালগাছী দরিদ্র উন্নয়ন সংস্থা',
                'org_type' => 'Non-Governmental Organization (NGO)',
                'mobile_app_name' => 'TPDA e-Sheba',
                'contact_person' => 'Md. Mostafa',
                'is_active' => true,
            ]
        );
    }
}
