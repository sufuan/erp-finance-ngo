<?php

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor authentication (plan Step 2). Mandatory for Super
 * Admin, Branch Manager and Accountant — but only while the master
 * switch (Settings → General Settings → Security, key
 * security.totp_enabled, default OFF) is on. When the switch is off
 * nobody is forced to enroll or pass a challenge.
 */
class TotpService
{
    /** Roles that MUST enroll in TOTP (plan Step 2). */
    public const MANDATORY_ROLES = ['super-admin', 'branch-manager', 'accountant'];

    public function __construct(
        private readonly Google2FA $google2fa,
        private readonly SettingsService $settings,
    ) {}

    /** Master switch for the whole TOTP system. Default OFF. */
    public function enabled(): bool
    {
        return (bool) $this->settings->get('security.totp_enabled', false);
    }

    public function requiredFor(User $user): bool
    {
        return $this->enabled() && $user->hasAnyRole(self::MANDATORY_ROLES);
    }

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    /** otpauth:// URL — the app name comes from settings, never hard-coded. */
    public function qrCodeUrl(User $user, string $secret, string $issuer): string
    {
        return $this->google2fa->getQRCodeUrl($issuer, $user->username, $secret);
    }

    public function qrSvg(User $user, string $secret, string $issuer): string
    {
        $renderer = new ImageRenderer(new RendererStyle(200), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($this->qrCodeUrl($user, $secret, $issuer));
    }

    public function verify(string $secret, string $code): bool
    {
        return $this->google2fa->verifyKey($secret, preg_replace('/\s+/', '', $code));
    }
}
