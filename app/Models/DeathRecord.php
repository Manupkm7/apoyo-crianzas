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
 * Registro de defunción de un niño.
 *
 * cause_of_death es el campo más sensible del sistema entero:
 *   - Se cifra con AES-256 (cast 'encrypted')
 *   - Es nullable (el acta puede registrarse sin causa determinada)
 *   - Se excluye COMPLETAMENTE del audit log — no aparece en ningún historial
 *
 * El campo mother_dni_hash permite vincular este registro con otros registros
 * del sistema (BirthRecord, HealthRecord, etc.) que compartan la misma madre,
 * sin necesidad de descifrar el DNI.
 *
 * child_id es nullable porque el niño puede no existir en `children` si nunca
 * fue ingresado al sistema (p.ej. muertes neonatales sin historia previa).
 */
class DeathRecord extends Model
{
    use HasFactory, HasUuids, LogsActivity, SoftDeletes;

    protected $fillable = [
        'child_id',
        'institution_id',
        'first_name',
        'last_name',
        'birth_date',
        'death_date',
        'child_dni',
        'child_dni_hash',
        'mother_dni',
        'mother_dni_hash',
        'cause_of_death',
        'observations',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'birth_date'     => 'date',
            'death_date'     => 'date',
            'child_dni'      => 'encrypted',
            'mother_dni'     => 'encrypted',
            'cause_of_death' => 'encrypted',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        // cause_of_death se excluye del audit log completamente — dato ultra sensible.
        // Los DNIs tampoco: solo se auditan los campos no identificatorios.
        return LogOptions::defaults()
            ->logOnly([
                'first_name',
                'last_name',
                'birth_date',
                'death_date',
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
