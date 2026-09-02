<?php

namespace App\Models;

use App\Services\Import\ImportMatchingService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'birth_date_is_placeholder',
        'dni',
        'dni_hash',
        'name_normalized',
        'name_no_accents',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'birth_date'                => 'date',
            'birth_date_is_placeholder' => 'boolean',
            'dni'                       => 'encrypted', // AES-256 vía APP_KEY; se cifra al guardar, se descifra al leer
        ];
    }

    /**
     * Mantiene name_normalized/name_no_accents sincronizados con first_name/last_name
     * — se usan para buscar candidatos por nombre en ImportMatchingService::matchChild()
     * sin tener que normalizar en cada consulta.
     */
    protected static function booted(): void
    {
        static::saving(function (Child $child) {
            if ($child->isDirty('first_name') || $child->isDirty('last_name') || $child->name_normalized === null) {
                $child->name_normalized = ImportMatchingService::normalizeName($child->first_name, $child->last_name);
                $child->name_no_accents = ImportMatchingService::normalizeNameNoAccents($child->first_name, $child->last_name);
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        // DNI nunca va al historial de auditoría. birth_date sí, porque es relevante para alertas.
        return LogOptions::defaults()
            ->logOnly(['first_name', 'last_name', 'birth_date', 'notes'])
            ->logOnlyDirty();
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

    /**
     * Gestiones de alertas del SAT (histórico). Ver App\Services\ChildAlertEvaluator:
     * las alertas se calculan al vuelo; acá solo se guarda cuándo se marcaron como
     * "en seguimiento". Sin orden por defecto — cada consumidor ordena como necesita.
     */
    public function alertAcknowledgements(): HasMany
    {
        return $this->hasMany(AlertAcknowledgement::class);
    }

    public function birthRecord(): HasOne
    {
        return $this->hasOne(BirthRecord::class);
    }

    public function deathRecord(): HasOne
    {
        return $this->hasOne(DeathRecord::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
