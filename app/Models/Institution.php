<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

/*
 * Institución municipal del sistema.
 *
 * Cada institución tiene un tipo (salud, educacion, desarrollo_social, etc.)
 * que determina qué módulos de datos podrán usar sus usuarios.
 * Los cambios en nombre, tipo y estado activo quedan registrados automáticamente
 * en el historial de auditoría.
 */

class Institution extends Model
{
    use HasFactory, HasUuids, LogsActivity, SoftDeletes;

    // Sala máxima fija de jardín maternal e infantil (no varía por institución).
    public const JARDIN_MAX_GRADE = 5;

    protected $fillable = [
        'name',
        'type',
        'address',
        'phone',
        'is_active',
        'offers_jardin',
        'offers_primario',
        'primario_years',
        'offers_secundario',
        'secundario_years',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active'         => 'boolean',
            'offers_jardin'     => 'boolean',
            'offers_primario'   => 'boolean',
            'primario_years'    => 'integer',
            'offers_secundario' => 'boolean',
            'secundario_years'  => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'name', 'type', 'is_active',
                'offers_jardin', 'offers_primario', 'primario_years',
                'offers_secundario', 'secundario_years',
            ])
            ->logOnlyDirty();
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Niveles educativos que ofrece esta institución, con su grado máximo.
     * Solo tiene sentido para instituciones de tipo 'educacion'.
     *
     * Ej: ['primario' => ['label' => 'Primario', 'max_grade' => 7], ...]
     */
    public function educationLevelDefinitions(): array
    {
        $levels = [];

        if ($this->offers_jardin) {
            $levels['jardin'] = ['label' => 'Jardín maternal e infantil', 'max_grade' => self::JARDIN_MAX_GRADE];
        }

        if ($this->offers_primario && $this->primario_years) {
            $levels['primario'] = ['label' => 'Primario', 'max_grade' => $this->primario_years];
        }

        if ($this->offers_secundario && $this->secundario_years) {
            $levels['secundario'] = ['label' => 'Secundario', 'max_grade' => $this->secundario_years];
        }

        return $levels;
    }

    /**
     * Grado máximo válido para un nivel dado, o null si la institución no ofrece ese nivel.
     */
    public function maxGradeForLevel(string $level): ?int
    {
        return $this->educationLevelDefinitions()[$level]['max_grade'] ?? null;
    }
}
