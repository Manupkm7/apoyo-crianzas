<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Una entrada de la bitácora de observaciones de un registro de salud.
 *
 * A diferencia de HealthRecord::observations (texto libre, se sobrescribe),
 * cada HealthObservation es una entrada individual con autor y fecha, que
 * opcionalmente lleva un PDF adjunto (guardado en el disco 'local', nunca público).
 *
 * Solo la institución de salud dueña del registro puede crear entradas.
 * Representantes solo leen.
 */
class HealthObservation extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'health_record_id',
        'author_id',
        'author_type',
        'body',
        'attachment_path',
        'attachment_original_name',
        'attachment_mime',
        'attachment_size',
    ];

    protected function casts(): array
    {
        return [
            'attachment_size' => 'integer',
        ];
    }

    public function healthRecord(): BelongsTo
    {
        return $this->belongsTo(HealthRecord::class);
    }

    /**
     * Autor de la entrada: un User (cuenta personal) o una Institution (login
     * institucional). Ambos exponen ->name, que es lo que consume el Resource.
     */
    public function author(): MorphTo
    {
        return $this->morphTo();
    }
}
