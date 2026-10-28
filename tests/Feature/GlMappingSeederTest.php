<?php

namespace Tests\Feature;

use App\Models\GlMapping;
use App\Models\Ledger;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlMappingSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * GlMappingSeeder resolves every ledger through Ledger::byCode(), which is
 * nullable — without a chart of accounts it must fail with a clear message
 * naming the mapping and the missing code, not a null-property crash.
 */
class GlMappingSeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_missing_chart_of_accounts_fails_with_clear_message(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            Ledger::query()->delete();
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->assertSame(0, Ledger::count());

        try {
            (new GlMappingSeeder)->run();
            $this->fail('GlMappingSeeder should have thrown a RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('deposit.savings.receive', $e->getMessage());
            $this->assertStringContainsString('1010', $e->getMessage());
            $this->assertStringContainsString('ChartOfAccountsSeeder', $e->getMessage());
        }
    }

    public function test_seeds_mappings_after_chart_of_accounts(): void
    {
        (new ChartOfAccountsSeeder)->run();
        (new GlMappingSeeder)->run();

        foreach (['collection.other', 'collection.fee'] as $key) {
            $mapping = GlMapping::byKey($key);
            $this->assertNotNull($mapping, "Missing gl_mappings row for {$key}");
            $this->assertNotNull($mapping->debit_ledger_id);
            $this->assertNotNull($mapping->credit_ledger_id);
        }

        $cash = Ledger::byCode('1010');
        $this->assertNotNull($cash);
        $this->assertSame($cash->id, GlMapping::byKey('collection.other')->debit_ledger_id);
        $this->assertSame($cash->id, GlMapping::byKey('collection.fee')->debit_ledger_id);
    }
}
