<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserImportBatch extends Model
{
    use HasUuids;

    protected $fillable = [
        'institution_id',
        'status',
        'original_filename',
        'total_rows',
        'created_rows',
        'needs_review_rows',
        'skipped_rows',
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
        return $this->hasMany(UserImportRow::class, 'batch_id');
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
                SUM(CASE WHEN status = 'created'      THEN 1 ELSE 0 END) as created,
                SUM(CASE WHEN status = 'needs_review' THEN 1 ELSE 0 END) as needs_review,
                SUM(CASE WHEN status = 'skipped'      THEN 1 ELSE 0 END) as skipped,
                SUM(CASE WHEN status = 'error'        THEN 1 ELSE 0 END) as errors
            ")
            ->first();

        $this->update([
            'status'             => 'completed',
            'finished_at'        => now(),
            'created_rows'       => $counts->created,
            'needs_review_rows'  => $counts->needs_review,
            'skipped_rows'       => $counts->skipped,
            'error_rows'         => $counts->errors,
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
