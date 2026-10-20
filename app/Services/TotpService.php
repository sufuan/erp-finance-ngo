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
 * Admin, Branch Manager and Accountant; optional for everyone else.
 */
class TotpService
{
    /** Roles that MUST enroll in TOTP (plan Step 2). */
    public const MANDATORY_ROLES = ['super-admin', 'branch-manager', 'accountant'];

    public function __construct(private readonly Google2FA $google2fa) {}

    public function requiredFor(User $user): bool
    {
        return $user->hasAnyRole(self::MANDATORY_ROLES);
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
