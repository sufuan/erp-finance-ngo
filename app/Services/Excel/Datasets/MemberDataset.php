<?php

namespace App\Services\Excel\Datasets;

use App\Models\Member;
use App\Models\MemberImportBatch;
use App\Models\Samity;
use App\Models\User;
use App\Services\Excel\ImportDataset;
use App\Services\MemberAdmissionService;
use App\Services\MemberImportRowValidator;

/**
 * Members dataset for the Excel engine (Member Migrations). Validation
 * is the Step 9A MemberImportRowValidator — the same rules the legacy
 * dry-run/job flow uses, with errors attributed per cell; saving goes
 * through MemberAdmissionService, the one admission path (member number,
 * share posting, member portal login) — never a fork of it.
 */
class MemberDataset implements ImportDataset
{
    public function __construct(
        private MemberImportRowValidator $validator,
        private MemberAdmissionService $admissions,
    ) {}

    public function key(): string
    {
        return 'members';
    }

    public function sheetName(): string
    {
        return 'Members';
    }

    public function columns(): array
    {
        return [
            ['key' => 'member_id', 'header' => 'MemberID', 'label' => 'member_no', 'required' => false,
                'example' => 'LEGACY-001', 'hint' => 'excel_hint_member_id', 'input' => 'text',
                'aliases' => ['memberid', 'memberno', 'id']],
            ['key' => 'name', 'header' => 'MemberName', 'label' => 'full_name', 'required' => true,
                'example' => 'Fatima Begum', 'hint' => 'excel_hint_name', 'input' => 'text',
                'aliases' => ['membername', 'name', 'fullname']],
            ['key' => 'samity', 'header' => 'Samity', 'label' => 'samity', 'required' => true,
                'example' => 'Talgachi Samity', 'hint' => 'excel_hint_samity', 'input' => 'text',
                'aliases' => ['samity', 'samityname', 'samitycode']],
            ['key' => 'gender', 'header' => 'Gender', 'label' => 'gender', 'required' => true,
                'example' => 'Female', 'hint' => 'excel_hint_gender', 'input' => 'select',
                'options' => ['Male', 'Female', 'Other'],
                'aliases' => ['gender', 'sex']],
            ['key' => 'father_name', 'header' => "Father's/Husband's Name", 'label' => 'fathers_husbands_name', 'required' => true,
                'example' => 'Abdul Karim', 'hint' => null, 'input' => 'text',
                'aliases' => ['fathershusbandsname', 'fathersname', 'fatherhusbandname', 'fathername', 'father']],
            ['key' => 'mobile', 'header' => 'Mobile', 'label' => 'mobile', 'required' => true,
                'example' => '01712345678', 'hint' => 'excel_hint_mobile', 'input' => 'text',
                'aliases' => ['mobile', 'mobileno', 'mobilenumber', 'phone']],
            ['key' => 'present_address', 'header' => 'Present Address', 'label' => 'present_address', 'required' => true,
                'example' => 'Talgachi, Shahjadpur, Sirajganj', 'hint' => null, 'input' => 'text',
                'aliases' => ['presentaddress', 'address']],
            ['key' => 'admission_date', 'header' => 'Admission Date', 'label' => 'open_date', 'required' => true,
                'example' => '2021-03-01', 'hint' => 'excel_hint_admission_date', 'input' => 'date',
                'aliases' => ['admissiondate', 'opendate', 'joiningdate', 'date']],
            ['key' => 'national_id', 'header' => 'NID', 'label' => 'national_id', 'required' => false,
                'example' => '1234567890', 'hint' => 'excel_hint_national_id', 'input' => 'text',
                'aliases' => ['nid', 'nationalid', 'nationalidno', 'nidno']],
            ['key' => 'primary_product', 'header' => 'Primary Product', 'label' => 'primary_product', 'required' => false,
                'example' => '', 'hint' => null, 'input' => 'text',
                'aliases' => ['primaryproduct', 'product']],
        ];
    }

    public function validateRows(array $rows, array $context, User $actor): array
    {
        $defaultSamity = isset($context['samity_id']) && $context['samity_id']
            ? Samity::withoutGlobalScopes()->find((int) $context['samity_id'])
            : null;

        $seenMobiles = [];
        $seenNids = [];
        $out = [];
        foreach ($rows as $cells) {
            $result = $this->validator->validateCells($cells, $defaultSamity, $seenMobiles, $seenNids);
            $out[] = ['cells' => $cells, 'errors' => $result['cell_errors'], 'data' => $result['data']];
        }

        return $out;
    }

    public function saveRow(array $data, User $actor): void
    {
        $samity = Samity::withoutGlobalScopes()->findOrFail($data['samity_id']);
        $this->admissions->admit($data, $samity, $actor->id);
    }

    public function exportRows(User $actor): array
    {
        // Default scopes apply: the export lists the members visible in
        // the actor's branch context (SamityScope), like the member list.
        return Member::with('samity:id,samity_code')
            ->orderBy('id')
            ->get()
            ->map(fn (Member $m) => [
                'member_id' => (string) $m->member_no,
                'name' => (string) $m->name,
                'samity' => (string) ($m->samity?->samity_code ?? ''),
                'gender' => (string) $m->gender,
                'father_name' => (string) ($m->father_name ?? ''),
                'mobile' => (string) $m->mobile,
                'present_address' => (string) ($m->present_address ?? ''),
                'admission_date' => $m->open_date?->toDateString() ?? '',
                'national_id' => (string) ($m->national_id ?? ''),
                'primary_product' => '',
            ])->all();
    }

    public function recordBatch(User $actor, string $source, array $context, array $rows, int $imported): ?int
    {
        $batch = MemberImportBatch::create([
            'created_by' => $actor->id,
            'samity_id' => isset($context['samity_id']) && $context['samity_id'] ? (int) $context['samity_id'] : null,
            'source' => in_array($source, ['excel', 'grid'], true) ? $source : 'excel',
            'status' => 'Completed',
            'total_rows' => count($rows),
            'imported_count' => $imported,
            'failed_count' => 0,
            'rows' => collect($rows)->map(fn (array $r, int $i) => [
                'no' => $i + 1, 'raw' => $r['cells'], 'errors' => [],
            ])->values()->all(),
        ]);

        return $batch->id;
    }
}
