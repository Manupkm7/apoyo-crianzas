<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRow extends Model
{
    use HasUuids;

    protected $fillable = [
        'batch_id',
        'status',
        'raw_data',
        'name_normalized',
        'name_no_accents',
        'birth_date',
        'dni_hash',
        'match_confidence',
        'match_notes',
        'matched_row_id',
        'child_id',
        'resolved_by',
        'resolved_at',
        'error_message',
        'file_line_number',
    ];

    protected function casts(): array
    {
        return [
            'raw_data'    => 'encrypted', // JSON con todos los campos PII del archivo original
            'birth_date'  => 'date',
            'resolved_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'batch_id');
    }

    public function matchedRow(): BelongsTo
    {
        return $this->belongsTo(ImportRow::class, 'matched_row_id');
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
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

    public function isFromCivilRegistry(): bool
    {
        return $this->batch->source === 'civil_registry';
    }

    public function isFromEducation(): bool
    {
        return $this->batch->source === 'education';
    }
}
