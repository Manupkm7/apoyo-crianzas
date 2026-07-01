<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

/**
 * Registro base de un niño en el sistema.
 *
 * Actúa como punto de conexión entre los distintos dominios: una institución de salud
 * y una institución de educación pueden tener sus propios registros (HealthRecord,
 * EducationRecord) apuntando al mismo niño.
 *
 * El DNI se cifra automáticamente con AES-256 (cast 'encrypted' usa la APP_KEY).
 * Para detectar duplicados sin guardar el DNI en texto legible, se calcula su
 * SHA-256 en el controlador y se guarda en dni_hash antes de persistir.
 *
 * Auditoría: los cambios en nombre y fecha de nacimiento quedan registrados.
 * El DNI NUNCA aparece en el historial de auditoría por ser dato altamente sensible.
 */
class Child extends Model
{
    use HasFactory, HasUuids, LogsActivity, SoftDeletes;

    protected $fillable = [
        'first_name',
        'last_name',
        'birth_date',
        'dni',
        'dni_hash',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'dni'        => 'encrypted', // AES-256 vía APP_KEY; se cifra al guardar, se descifra al leer
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        // DNI nunca va al historial de auditoría. birth_date sí, porque es relevante para alertas.
        return LogOptions::defaults()
            ->logOnly(['first_name', 'last_name', 'birth_date', 'notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Calcula la edad actual del niño en años a partir de su fecha de nacimiento.
     * Se accede como propiedad: $child->age
     * La edad no se guarda en la BD porque cambiaría todos los días sin que nadie la actualice.
     */
    public function getAgeAttribute(): int
    {
        return $this->birth_date->diffInYears(now());
    }

    /**
     * Registro educativo asociado (creado por una institución de educación).
     */
    public function educationRecord(): HasOne
    {
        return $this->hasOne(EducationRecord::class);
    }

    /**
     * Registro de salud asociado (creado por una institución de salud).
     */
    public function healthRecord(): HasOne
    {
        return $this->hasOne(HealthRecord::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
