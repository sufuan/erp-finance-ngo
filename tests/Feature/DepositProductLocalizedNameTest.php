<?php

namespace Tests\Feature;

use App\Models\DepositProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Deposit product names follow the UI language (owner order 2026-11-18):
 * Bangla UI must show the Bangla product names, English UI the English
 * ones; codes never change; a product without a Bangla name falls back
 * to its English name.
 */
class DepositProductLocalizedNameTest extends TestCase
{
    use DatabaseTransactions;

    private const EXPECTED = [
        'SAVINGS' => ['General Savings', 'সাধারণ সঞ্চয়'],
        'CSAV' => ['Compulsory Savings', 'বাধ্যতামূলক সঞ্চয়'],
        'DPS' => ['Deposit Pension Scheme', 'ডিপোজিট পেনশন স্কিম'],
        'FDR' => ['Fixed Deposit', 'ফিক্সড ডিপোজিট'],
        'LTS' => ['Long Term Savings', 'দীর্ঘমেয়াদী সঞ্চয়'],
    ];

    private function admin(): User
    {
        $user = User::factory()->create(['must_change_password' => false]);
        $user->assignRole('super-admin');
        $this->actingAs($user);
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);

        return $user;
    }

    public function test_localized_name_follows_locale_for_all_five_products(): void
    {
        foreach (self::EXPECTED as $code => [$en, $bn]) {
            $product = DepositProduct::where('code', $code)->firstOrFail();

            app()->setLocale('en');
            $this->assertSame($en, $product->localized_name, "{$code} in English");

            app()->setLocale('bn');
            $this->assertSame($bn, $product->localized_name, "{$code} in Bangla");
        }
    }

    public function test_opening_page_serves_both_names_for_every_product(): void
    {
        $this->admin();

        $response = $this->get('/deposits/opening')->assertOk();
        $products = collect($response->viewData('page')['props']['products']);

        foreach (self::EXPECTED as $code => [$en, $bn]) {
            $row = $products->firstWhere('code', $code);
            $this->assertNotNull($row, "{$code} missing from the opening dropdown data");
            $this->assertSame($en, $row['name_en']);
            $this->assertSame($bn, $row['name_bn']);
        }
    }

    public function test_products_list_page_serves_both_names(): void
    {
        $this->admin();

        $response = $this->get('/deposits/products')->assertOk();
        $products = collect($response->viewData('page')['props']['products']);
        $dps = $products->firstWhere('code', 'DPS');
        $this->assertSame('ডিপোজিট পেনশন স্কিম', $dps['name_bn']);
    }

    public function test_custom_product_without_bangla_name_falls_back_to_english(): void
    {
        $this->admin();

        $this->post('/deposits/products', [
            'code' => 'TESTPROD', 'name_en' => 'Test Product', 'name_bn' => '',
            'gl_mapping_prefix' => 'deposit.savings', 'is_active' => true,
        ])->assertSessionHas('success');

        $product = DepositProduct::where('code', 'TESTPROD')->firstOrFail();
        app()->setLocale('bn');
        $this->assertSame('Test Product', $product->localized_name);

        // And one WITH a Bangla name localizes properly.
        $this->put("/deposits/products/{$product->id}", [
            'code' => 'TESTPROD', 'name_en' => 'Test Product', 'name_bn' => 'টেস্ট পণ্য',
            'gl_mapping_prefix' => 'deposit.savings', 'is_active' => true,
        ])->assertSessionHas('success');
        app()->setLocale('bn'); // the PUT request itself resets locale from the user
        $this->assertSame('টেস্ট পণ্য', $product->fresh()->localized_name);
        app()->setLocale('en');
        $this->assertSame('Test Product', $product->fresh()->localized_name);
    }
}
