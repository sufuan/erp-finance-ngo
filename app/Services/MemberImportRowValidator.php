<?php

namespace App\Services;

use App\Models\Member;
use App\Models\Samity;
use Carbon\Carbon;

/**
 * Step 9A — validates + normalizes ONE migration row (from Excel or the
 * blank-rows grid). Purely read-only: the dry run runs it over every row
 * without saving anything; the queued import runs it again per row inside
 * that row's own transaction. Within-file duplicates are tracked by the
 * caller through $seenMobiles / $seenNids.
 */
class MemberImportRowValidator
{
    public function __construct(private IdentityCheckService $identity) {}

    /**
     * @param  array  $row  Raw keys: member_id, name, samity, gender,
     *                      father_name, mobile, present_address,
     *                      admission_date, national_id, primary_product.
     * @return array{data: ?array, errors: string[]}
     */
    public function validate(array $row, ?Samity $defaultSamity, array &$seenMobiles, array &$seenNids): array
    {
        $result = $this->validateCells($row, $defaultSamity, $seenMobiles, $seenNids);

        return ['data' => $result['data'], 'errors' => array_values($result['cell_errors'])];
    }

    /**
     * Same rules as validate(), but each error is attributed to the cell
     * (column key) that caused it — the Excel import/export engine's
     * editable preview renders these per cell. The flat list validate()
     * returns is exactly this map's values, in the same order.
     *
     * @return array{data: ?array, cell_errors: array<string, string>}
     */
    public function validateCells(array $row, ?Samity $defaultSamity, array &$seenMobiles, array &$seenNids): array
    {
        $errors = [];
        $get = fn (string $key) => isset($row[$key]) ? trim((string) $row[$key]) : '';

        $name = $get('name');
        if ($name === '') {
            $errors['name'] = __('required_field').': '.__('full_name');
        }

        $samity = $this->resolveSamity($get('samity'), $defaultSamity);
        if (! $samity) {
            $errors['samity'] = __('samity_not_found');
        }

        $gender = match (strtolower($get('gender'))) {
            'male', 'm' => 'Male',
            'female', 'f' => 'Female',
            'other', 'others' => 'Other',
            default => null,
        };
        if ($gender === null) {
            $errors['gender'] = __('required_field').': '.__('gender');
        }

        $fatherName = $get('father_name');
        if ($fatherName === '') {
            $errors['father_name'] = __('required_field').': '.__('fathers_husbands_name');
        }

        $mobile = preg_replace('/\D/', '', $get('mobile'));
        if ($mobile === '') {
            $errors['mobile'] = __('required_field').': '.__('mobile');
        } elseif (! preg_match('/^01[3-9]\d{8}$/', $mobile)) {
            $errors['mobile'] = __('invalid_mobile_format');
        } elseif (isset($seenMobiles[$mobile])) {
            $errors['mobile'] = __('duplicate_in_file').': '.__('mobile');
        } elseif ($this->identity->mobileTaken($mobile)) {
            $errors['mobile'] = __('duplicate_mobile');
        } else {
            $seenMobiles[$mobile] = true;
        }

        $address = $get('present_address');
        if ($address === '') {
            $errors['present_address'] = __('required_field').': '.__('present_address');
        }

        $openDate = $this->parseDate($get('admission_date'));
        if ($openDate === null) {
            $errors['admission_date'] = __('invalid_date').': '.__('open_date');
        }

        $nid = preg_replace('/\D/', '', $get('national_id'));
        if ($nid !== '') {
            if (! preg_match('/^\d{10}$|^\d{13}$|^\d{17}$/', $nid)) {
                $errors['national_id'] = __('invalid_nid_format');
            } elseif (isset($seenNids[$nid])) {
                $errors['national_id'] = __('duplicate_in_file').': '.__('national_id');
            } elseif ($this->identity->nidTaken($nid)) {
                $errors['national_id'] = __('duplicate_nid');
            } else {
                $seenNids[$nid] = true;
            }
        }

        $memberNo = $get('member_id');
        if ($memberNo !== '' && Member::withoutGlobalScopes()->where('member_no', $memberNo)->exists()) {
            $errors['member_id'] = __('duplicate_member_no');
        }

        if ($errors !== []) {
            return ['data' => null, 'cell_errors' => $errors];
        }

        return ['data' => [
            'member_no' => $memberNo !== '' ? $memberNo : null,
            'samity_id' => $samity->id,
            'name' => $name,
            'father_name' => $fatherName,
            'gender' => $gender,
            'mobile' => $mobile,
            'present_address' => $address,
            'permanent_address' => $address,
            'open_date' => $openDate,
            'national_id' => $nid !== '' ? $nid : null,
            'share_amount' => 0,
        ], 'cell_errors' => []];
    }

    private function resolveSamity(string $value, ?Samity $default): ?Samity
    {
        if ($value === '') {
            return $default;
        }
        $query = Samity::withoutGlobalScopes();
        if (ctype_digit($value)) {
            $byId = (clone $query)->find((int) $value);
            if ($byId) {
                return $byId;
            }
        }

        return $query->where('samity_code', $value)
            ->orWhereRaw('LOWER(name) = ?', [strtolower($value)])
            ->first() ?? $default;
    }

    private function parseDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        if (is_numeric($value) && (float) $value > 20000) {
            // Excel serial date.
            return XlsxService::serialToDate((float) $value);
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y', 'j M Y', 'j-M-y', 'd M Y'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $value);
            if ($parsed !== false) {
                return $parsed->format('Y-m-d');
            }
        }
        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
