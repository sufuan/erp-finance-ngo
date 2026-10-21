<?php

namespace App\Models;

use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Model;

/**
 * Step 15 — installment frequency master. Which frequencies are usable is
 * a SETTING (`loan.enabled_frequencies`); disabling a code there removes
 * it from every loan form.
 */
class InstallmentFrequency extends Model
{
    protected $fillable = ['code', 'label_en', 'label_bn', 'days_interval'];

    /** Frequencies currently enabled by the setting, in master order. */
    public static function enabled(): array
    {
        $codes = app(SettingsService::class)->get('loan.enabled_frequencies', ['D', 'W', 'M']);
        if (is_string($codes)) {
            $codes = json_decode($codes, true) ?: ['D', 'W', 'M'];
        }

        return static::whereIn('code', $codes)->orderBy('id')->get()->all();
    }
}
