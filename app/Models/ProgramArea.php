<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Dependencia programática de un efector: el área de gobierno de la que depende
 * (ej. "Secretaría de Salud municipal"). Catálogo que crece desde el formulario
 * de institución (menú con las ya cargadas + agregar nueva).
 */
class ProgramArea extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'name_normalized',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::saving(function (ProgramArea $area) {
            $area->name = Str::squish($area->name);
            $area->name_normalized = self::normalizeName($area->name);
        });
    }

    public function institutions(): HasMany
    {
        return $this->hasMany(Institution::class);
    }

    public static function normalizeName(string $name): string
    {
        return Str::of($name)->ascii()->lower()->squish()->toString();
    }
}
