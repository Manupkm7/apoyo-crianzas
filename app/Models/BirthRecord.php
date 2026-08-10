<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

/**
 * Registro de nacimiento cargado por una institución de salud.
 *
 * Los campos mother_dni, father_dni y address se cifran automáticamente con
 * AES-256 via APP_KEY (cast 'encrypted'). Ningún DNI aparece en el audit log.
 *
 * El flujo típico al crear un BirthRecord es:
 *   1. Calcular mother_dni_hash = hash('sha256', $rawMotherDni)
 *   2. Calcular father_dni_hash si se provee el padre
 *   3. Persistir; el cast 'encrypted' cifra los valores antes de escribir en BD
 *   4. Opcionalmente vincular child_id si ya existe el niño en `children`
 */
class BirthRecord extends Model
{
    use HasFactory, HasUuids, LogsActivity, SoftDeletes;

    protected $fillable = [
        'child_id',
        'institution_id',
        'first_name',
        'last_name',
        'birth_date',
        'mother_name',
        'mother_dni',
        'mother_dni_hash',
        'father_name',
        'father_dni',
        'father_dni_hash',
        'address',
        'birth_establishment',
        'observations',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'birth_date'  => 'date',
            'mother_name' => 'encrypted',
            'mother_dni'  => 'encrypted',
            'father_name' => 'encrypted',
            'father_dni'  => 'encrypted',
            'address'     => 'encrypted',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        // DNIs y address NUNCA aparecen en auditoría — son el dato más sensible.
        // mother_name y father_name tampoco: son PII de adultos terceros.
        return LogOptions::defaults()
            ->logOnly([
                'first_name',
                'last_name',
                'birth_date',
                'birth_establishment',
                'observations',
                'child_id',
                'institution_id',
            ])
            ->logOnlyDirty();
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
