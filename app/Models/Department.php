<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/*
 * Departamento — nivel intermedio del catálogo geográfico, entre Provincia y Localidad.
 */
class Department extends Model
{
    use HasUuids;

    protected $fillable = [
        'province_id',
        'external_code',
        'name',
    ];

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function localities(): HasMany
    {
        return $this->hasMany(Locality::class);
    }
}
