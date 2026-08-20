<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/*
 * Localidad — nivel más bajo del catálogo geográfico. Las instituciones se
 * ubican en una localidad, que es el filtro final del login institucional
 * antes de elegir sector e institución.
 */
class Locality extends Model
{
    use HasUuids;

    protected $fillable = [
        'department_id',
        'external_code',
        'name',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function institutions(): HasMany
    {
        return $this->hasMany(Institution::class);
    }
}
