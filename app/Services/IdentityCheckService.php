<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-register identity uniqueness (plan Step 6/8): a national ID or a
 * mobile number may belong to exactly ONE person across employees,
 * directors and members. Lookups run on SHA-256 hashes / plain mobile
 * columns so encrypted NIDs are never decrypted for a duplicate check.
 */
class IdentityCheckService
{
    /** Tables that carry a nid_hash column, in check order. */
    private function nidTables(): array
    {
        return collect(['employees', 'directors', 'members'])
            ->filter(fn (string $t) => Schema::hasTable($t) && Schema::hasColumn($t, 'nid_hash'))
            ->all();
    }

    private function mobileTables(): array
    {
        return collect(['employees', 'directors', 'members'])
            ->filter(fn (string $t) => Schema::hasTable($t) && Schema::hasColumn($t, 'mobile'))
            ->all();
    }

    public static function nidHash(string $nid): string
    {
        return hash('sha256', preg_replace('/\D/', '', $nid));
    }

    public static function passportHash(string $passport): string
    {
        return hash('sha256', strtoupper(trim($passport)));
    }

    /** True when the NID already belongs to someone else. */
    public function nidTaken(string $nid, ?string $exceptTable = null, ?int $exceptId = null): bool
    {
        $hash = self::nidHash($nid);
        foreach ($this->nidTables() as $table) {
            $query = DB::table($table)->where('nid_hash', $hash);
            if ($table === $exceptTable && $exceptId) {
                $query->where('id', '!=', $exceptId);
            }
            if ($query->exists()) {
                return true;
            }
        }

        return false;
    }

    /** True when the mobile already belongs to someone else. */
    public function mobileTaken(string $mobile, ?string $exceptTable = null, ?int $exceptId = null): bool
    {
        foreach ($this->mobileTables() as $table) {
            $query = DB::table($table)->where('mobile', $mobile);
            if ($table === $exceptTable && $exceptId) {
                $query->where('id', '!=', $exceptId);
            }
            if ($query->exists()) {
                return true;
            }
        }

        return false;
    }

    public function passportTaken(string $passport, ?string $exceptTable = null, ?int $exceptId = null): bool
    {
        $hash = self::passportHash($passport);
        foreach ($this->nidTables() as $table) {
            if (! Schema::hasColumn($table, 'passport_hash')) {
                continue;
            }
            $query = DB::table($table)->where('passport_hash', $hash);
            if ($table === $exceptTable && $exceptId) {
                $query->where('id', '!=', $exceptId);
            }
            if ($query->exists()) {
                return true;
            }
        }

        return false;
    }
}
