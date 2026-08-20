<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/*
 * Provincia — nivel más alto del catálogo geográfico usado por el login
 * institucional (Provincia → Departamento → Localidad → Institución).
 */
class Province extends Model
{
    use HasUuids;

    protected $fillable = [
        'external_code',
        'name',
    ];

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }
}
