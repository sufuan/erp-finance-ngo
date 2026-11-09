<?php

namespace Tests\Feature;

use App\Models\BusinessDay;
use App\Models\User;
use App\Services\BusinessDayService;
use DomainException;
use Tests\Feature\Crud\CrudTestCase;

/**
 * Regression guard for the branch-less user crash on Accounts report
 * pages (owner-hit live, sync #70): a user with an accounts role but NO
 * branch assignment resolved to branch scope [0] (or [] for
 * ?branch_id=all), and AccountReportController::dates() fed 0 into
 * BusinessDayService::currentDate() — which tried to CREATE a
 * business_days row for branch 0 and died on the FK
 * (business_days_branch_id_foreign). Admin never saw it (super-admin
 * always has branches). Such a user must get the pages rendered EMPTY
 * (200), and no business_days row may ever be written for branch 0.
 */
class AccountsReportNoBranchTest extends CrudTestCase
{
    /** Tabular registry keys (mirror of AccountReportController::registry()). */
    private array $reportKeys = [
        'account_balance', 'account_receipt_payment', 'trial_balance', 'trial_balance_2',
        'ledger_statement', 'cash_book', 'daily_cash_book', 'account_statement',
        'customer_statement', 'receipt_payment', 'receipt_payment_2', 'income_expense_topsheet',
    ];

    private array $statementKeys = [
        'balance_sheet', 'balance_sheet_2', 'income_expenses', 'income_expenses_2', 'profit_loss',
    ];

    /** A reports-capable user with deliberately NO branch assignment. */
    private function actAsBranchlessUser(): User
    {
        $user = User::factory()->create(['must_change_password' => false]);
        $user->assignRole('accountant');
        $this->assertSame([], $user->branches()->pluck('branches.id')->all(), 'fixture user must have no branches');

        $this->actingAs($user);
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);

        return $user;
    }

    /** Read the Inertia page props from the rendered HTML shell. */
    private function inertiaProps(string $url): array
    {
        $html = $this->get($url)->assertOk()->getContent();
        $found = preg_match('/<script data-page="app"[^>]*>(.*?)<\/script>/s', $html, $m);
        $this->assertSame(1, $found, 'Inertia shell carries the page JSON');

        return json_decode($m[1], true)['props'] ?? [];
    }

    private function assertNoBusinessDayWritten(int $countBefore): void
    {
        $this->assertSame(0, BusinessDay::where('branch_id', 0)->count(), 'no business_days row for branch 0');
        $this->assertSame($countBefore, BusinessDay::count(), 'no business_days row was created at all');
    }

    private function assertSectionsEmpty(array $props): void
    {
        foreach ($props['sections'] ?? [] as $section) {
            $this->assertSame([], $section['rows'] ?? [], 'section rows must be empty');
            $this->assertSame([], $section['groups'] ?? [], 'section groups must be empty');
        }
    }

    private function assertMatrixCarriesNoFigures(array $props, string $key): void
    {
        if (($props['columnsTable'] ?? null) === null) {
            return;
        }
        // The by-branch matrix always composes its label rows (e.g. the
        // surplus line) from the totals column — with no branch data
        // every figure cell must still be zero.
        foreach ($props['columnsTable']['rows'] as $row) {
            foreach (array_slice($row, 1) as $cell) {
                $this->assertSame('0.00', $cell, $key.' matrix must carry no figures');
            }
        }
    }

    public function test_branchless_user_gets_every_report_page_empty_and_no_business_day_written(): void
    {
        $this->actAsBranchlessUser();
        $daysBefore = BusinessDay::count();

        foreach ($this->reportKeys as $key) {
            // Plain open — the owner's exact path (menu → page), dates()
            // runs even before Run is pressed.
            $this->get('/accounts/reports?report='.$key)->assertOk();

            // Run with the default (branch-0) scope.
            $props = $this->inertiaProps('/accounts/reports?report='.$key.'&run=1');
            $this->assertTrue($props['ran'], $key);
            $this->assertSame([], $props['rows'], $key.' rows must be empty');
            $this->assertSectionsEmpty($props);

            // Run with the consolidated scope — empty accessible list.
            $propsAll = $this->inertiaProps('/accounts/reports?report='.$key.'&run=1&branch_id=all');
            $this->assertSame([], $propsAll['rows'], $key.' (all) rows must be empty');
            $this->assertSectionsEmpty($propsAll);
        }

        $this->assertNoBusinessDayWritten($daysBefore);
    }

    public function test_branchless_user_gets_every_statement_page_empty(): void
    {
        $this->actAsBranchlessUser();
        $daysBefore = BusinessDay::count();

        foreach ($this->statementKeys as $key) {
            $this->get('/accounts/statements?statement='.$key)->assertOk();

            $props = $this->inertiaProps('/accounts/statements?statement='.$key.'&run=1');
            $this->assertTrue($props['ran'], $key);
            $this->assertSectionsEmpty($props);
            $this->assertMatrixCarriesNoFigures($props, $key);

            $propsAll = $this->inertiaProps('/accounts/statements?statement='.$key.'&run=1&branch_id=all');
            $this->assertSectionsEmpty($propsAll);
            $this->assertMatrixCarriesNoFigures($propsAll, $key.' (all)');
        }

        $this->assertNoBusinessDayWritten($daysBefore);
    }

    public function test_branchless_user_report_pdfs_render(): void
    {
        $this->actAsBranchlessUser();
        $daysBefore = BusinessDay::count();

        $this->get('/accounts/reports/pdf?report=trial_balance')->assertOk();
        $this->get('/accounts/reports/pdf?report=statement:balance_sheet')->assertOk();
        $this->get('/accounts/reports/pdf?report=daily_cash_book&branch_id=all')->assertOk();

        $this->assertNoBusinessDayWritten($daysBefore);
    }

    public function test_business_day_service_refuses_to_create_for_unknown_branch(): void
    {
        $days = app(BusinessDayService::class);
        $countBefore = BusinessDay::count();

        foreach ([0, 999999] as $bogusId) {
            try {
                $days->current($bogusId);
                $this->fail("current({$bogusId}) must refuse a non-existent branch");
            } catch (DomainException $e) {
                $this->assertStringContainsString((string) $bogusId, $e->getMessage());
            }
        }

        $this->assertSame($countBefore, BusinessDay::count(), 'refusal must not write any row');
    }
}
