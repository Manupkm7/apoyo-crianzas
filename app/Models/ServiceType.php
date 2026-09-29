<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

/**
 * Catálogo de prestaciones ("Control de niño sano", "AUH", "Educación inicial"...).
 *
 * Lo administra el admin. is_mandatory = todo niño debería tenerla en cada
 * período informado; si falta, el SAT levanta 'prestacion_obligatoria_faltante'
 * (ver App\Services\ChildAlertEvaluator).
 *
 * Una importación que trae una prestación que todavía no está en el catálogo la
 * agrega sola (no obligatoria) al confirmarse la fila — ver
 * ImportController::createChildServiceFromRow().
 */
class ServiceType extends Model
{
    use HasUuids, LogsActivity;

    protected $fillable = [
        'name',
        'name_normalized',
        'sector',
        'description',
        'is_mandatory',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_mandatory' => 'boolean',
            'is_active'    => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ServiceType $type) {
            if ($type->isDirty('name') || $type->name_normalized === null) {
                $type->name_normalized = self::normalizeName($type->name);
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'sector', 'description', 'is_mandatory', 'is_active'])
            ->logOnlyDirty();
    }

    public function childServices(): HasMany
    {
        return $this->hasMany(ChildService::class);
    }

    public function scopeMandatory(Builder $query): Builder
    {
        return $query->where('is_mandatory', true)->where('is_active', true);
    }

    /**
     * "Control  de Niño SANO " → "control de nino sano". Minúsculas, sin tildes y
     * con espacios colapsados, para que el mismo nombre escrito distinto en dos
     * archivos caiga en la misma entrada del catálogo.
     */
    public static function normalizeName(string $name): string
    {
        return Str::of($name)->ascii()->lower()->squish()->toString();
    }

    public static function findByName(string $name): ?self
    {
        return self::where('name_normalized', self::normalizeName($name))->first();
    }
}
