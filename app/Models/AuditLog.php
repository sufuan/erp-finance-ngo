<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only audit trail (plan B1). Rows are never updated or deleted:
 * the model hard-blocks both, and the app DB user additionally holds
 * INSERT+SELECT only at the MySQL grant level (`db:apply-grants`).
 */
class AuditLog extends Model
{
    protected $fillable = [
        'user_id', 'action', 'entity', 'entity_id',
        'old_values', 'new_values', 'ip_address', 'prev_hash', 'hash',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('audit_logs is append-only: UPDATE is forbidden.'));
        static::deleting(fn () => throw new LogicException('audit_logs is append-only: DELETE is forbidden.'));
    }
}
