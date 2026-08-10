<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    use HasUuids;

    protected $fillable = [
        'source',
        'institution_id',
        'status',
        'original_filename',
        'total_rows',
        'processed_rows',
        'matched_rows',
        'partial_rows',
        'no_match_rows',
        'error_rows',
        'uploaded_by',
        'started_at',
        'finished_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'started_at'  => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class, 'batch_id');
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function markAsProcessing(): void
    {
        $this->update(['status' => 'processing', 'started_at' => now()]);
    }

    public function markAsCompleted(): void
    {
        $counts = $this->rows()
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'matched'        THEN 1 ELSE 0 END) as matched,
                SUM(CASE WHEN status = 'partial_match'  THEN 1 ELSE 0 END) as partial,
                SUM(CASE WHEN status = 'no_match'       THEN 1 ELSE 0 END) as no_match,
                SUM(CASE WHEN status = 'error'          THEN 1 ELSE 0 END) as errors
            ")
            ->first();

        $this->update([
            'status'         => 'completed',
            'finished_at'    => now(),
            'processed_rows' => $counts->total,
            'matched_rows'   => $counts->matched,
            'partial_rows'   => $counts->partial,
            'no_match_rows'  => $counts->no_match,
            'error_rows'     => $counts->errors,
        ]);
    }

    public function markAsFailed(string $message): void
    {
        $this->update([
            'status'        => 'failed',
            'finished_at'   => now(),
            'error_message' => $message,
        ]);
    }
}
