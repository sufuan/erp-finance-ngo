<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'group', 'type', 'description', 'updated_by'];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Decode the stored text per the row's declared type. */
    public function decodedValue(): mixed
    {
        return match ($this->type) {
            'number' => $this->value === null ? null : (str_contains((string) $this->value, '.') ? (float) $this->value : (int) $this->value),
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json' => $this->value === null ? null : json_decode((string) $this->value, true),
            default => $this->value,
        };
    }

    public static function encodeValue(mixed $value, string $type): ?string
    {
        return match ($type) {
            'json' => $value === null ? null : json_encode($value),
            'boolean' => $value ? '1' : '0',
            default => $value === null ? null : (string) $value,
        };
    }
}
