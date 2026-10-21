<?php

namespace Tests\Feature;

use App\Models\InstallmentFrequency;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\LoanRateGuard;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Step 15 — loan masters: MRA cap on the effective rate, frequency setting. */
class LoanMastersTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['must_change_password' => false]);
        $this->admin->assignRole('super-admin');
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);
    }

    public function test_seeded_products_are_declining_at_tpda_default(): void
    {
        $general = LoanProduct::where('code', '3001')->firstOrFail();
        $this->assertSame('declining', $general->rate_method);
        $this->assertSame('15.00', (string) $general->profit_rate);
        $this->assertSame(['D', 'W', 'M'], collect(InstallmentFrequency::enabled())->map(fn ($f) => $f->code)->all());
    }

    public function test_mra_cap_rejects_25_percent_and_flat_15(): void
    {
        $guard = app(LoanRateGuard::class);
        $this->assertEqualsWithDelta(15.0, $guard->effectiveRatePercent('15', 'declining'), 0.001);
        $flat15 = $guard->effectiveRatePercent('15', 'flat');
        $this->assertGreaterThan(24, $flat15);          // ≈26.6% effective
        $this->assertLessThan(28, $flat15);
        $this->assertLessThan(24, $guard->effectiveRatePercent('12', 'flat'));

        foreach ([['25', 'declining'], ['15', 'flat']] as [$rate, $method]) {
            try {
                $guard->assertWithinCap($rate, $method);
                $this->fail("Rate {$rate} {$method} should breach the cap.");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $guard->assertWithinCap('15', 'declining');
        $guard->assertWithinCap('12', 'flat');
        $this->addToAssertionCount(2);

        // Same rejection through the product form.
        $this->actingAs($this->admin)->post('/loans/products', [
            'code' => '3999', 'name_en' => 'Too Dear', 'profit_rate' => 25,
            'rate_method' => 'declining', 'gl_mapping_prefix' => 'loan.general',
        ])->assertSessionHasErrors('profit_rate');
        $this->assertNull(LoanProduct::where('code', '3999')->first());
    }

    public function test_disabling_weekly_removes_it_from_enabled_frequencies(): void
    {
        app(SettingsService::class)->set('loan.enabled_frequencies', ['D', 'M']);
        $codes = collect(InstallmentFrequency::enabled())->map(fn ($f) => $f->code)->all();
        $this->assertSame(['D', 'M'], $codes);
        $this->actingAs($this->admin)->get('/loans/installment-settings')
            ->assertOk()->assertInertia(fn ($p) => $p->component('Loans/InstallmentSettings')
                ->where('frequencies.0.code', 'D')->where('frequencies.1.code', 'M')
                ->has('frequencies', 2));
    }

    public function test_template_rate_must_match_product_unless_override(): void
    {
        $product = LoanProduct::where('code', '3001')->firstOrFail();
        // Field officer role has loans.products? No — use a manager-like user
        // with the products permission but NOT rate-override.
        $clerk = User::factory()->create(['must_change_password' => false]);
        $clerk->givePermissionTo('loans.products');
        $this->actingAs($clerk)->post('/loans/installment-settings', [
            'loan_product_id' => $product->id, 'frequency' => 'M',
            'no_of_installments' => 10, 'profit_rate' => 12,
        ])->assertSessionHasErrors('profit_rate');

        // The super admin holds rate-override via the role: allowed.
        $this->actingAs($this->admin)->post('/loans/installment-settings', [
            'loan_product_id' => $product->id, 'frequency' => 'M',
            'no_of_installments' => 10, 'profit_rate' => 12,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('emi_option_templates', [
            'loan_product_id' => $product->id, 'no_of_installments' => 10, 'profit_rate' => 12,
        ]);
        $this->assertTrue(Permission::where('name', 'loans.rate-override')->exists());
    }

    public function test_pages_render(): void
    {
        $this->actingAs($this->admin)->get('/loans/products')
            ->assertOk()->assertInertia(fn ($p) => $p->component('Loans/Products')->has('products', 3));
        $this->actingAs($this->admin)->get('/loans/installment-settings')
            ->assertOk()->assertInertia(fn ($p) => $p->component('Loans/InstallmentSettings')->has('frequencies', 3));
    }
}
