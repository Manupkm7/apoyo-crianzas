<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserImportRow extends Model
{
    use HasUuids;

    protected $fillable = [
        'batch_id',
        'status',
        'raw_data',
        'dni_hash',
        'role',
        'review_reason',
        'notes',
        'created_user_id',
        'resolved_by',
        'resolved_at',
        'error_message',
        'file_line_number',
    ];

    protected function casts(): array
    {
        return [
            'raw_data'    => 'encrypted', // JSON con nombre/apellido/dni/rol tal como vinieron del archivo
            'resolved_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(UserImportBatch::class, 'batch_id');
    }

    public function createdUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Decodifica raw_data y devuelve el campo solicitado.
     * El cast 'encrypted' descifra automáticamente al acceder a raw_data.
     */
    public function getRawField(string $field): mixed
    {
        $data = json_decode($this->raw_data, true);
        return $data[$field] ?? null;
    }
}
