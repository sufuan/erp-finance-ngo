<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Request;

/**
 * Append-only audit trail with a sha256 hash chain (plan B1).
 *
 * Every sensitive mutation (postings, authorizations, cancellations,
 * RBAC/settings changes) writes one row: hash = sha256(prev_hash + payload).
 * PII and secrets are redacted before writing — the audit log must never
 * become a second copy of the sensitive data it watches.
 */
class AuditService
{
    /** Keys whose values are always replaced with [redacted]. */
    private const REDACT_KEYS = [
        'password', 'password_hash', 'totp_secret', 'national_id', 'nid',
        'passport_no', 'passport', 'tin', 'otp', 'bank_account_number',
        'remember_token', 'app_password',
    ];

    public function log(
        ?int $userId,
        string $action,
        string $entity,
        ?int $entityId = null,
        ?array $old = null,
        ?array $new = null,
    ): AuditLog {
        $prev = AuditLog::orderByDesc('id')->value('hash');

        $payload = [
            'user_id' => $userId,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'old_values' => $old ? $this->redact($old) : null,
            'new_values' => $new ? $this->redact($new) : null,
            'ip_address' => Request::ip(),
        ];

        $hash = hash('sha256', ($prev ?? '').json_encode($payload));

        return AuditLog::create([
            ...$payload,
            'prev_hash' => $prev,
            'hash' => $hash,
        ]);
    }

    public function redact(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACT_KEYS, true)) {
                $out[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $out[$key] = $this->redact($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Walk the chain and confirm no row was altered or removed.
     */
    public function verifyChain(): bool
    {
        $prev = null;
        foreach (AuditLog::orderBy('id')->cursor() as $row) {
            if ($row->prev_hash !== $prev) {
                return false;
            }
            $payload = [
                'user_id' => $row->user_id,
                'action' => $row->action,
                'entity' => $row->entity,
                'entity_id' => $row->entity_id,
                'old_values' => $row->old_values,
                'new_values' => $row->new_values,
                'ip_address' => $row->ip_address,
            ];
            if (! hash_equals($row->hash, hash('sha256', ($prev ?? '').json_encode($payload)))) {
                return false;
            }
            $prev = $row->hash;
        }

        return true;
    }
}
