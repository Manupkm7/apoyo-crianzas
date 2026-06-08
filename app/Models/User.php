<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, HasUuids, LogsActivity, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'institution_id',
        'is_active',
        'is_institution_head',
        'created_by',
        'updated_by',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'failed_login_attempts',
        'locked_until',
        'last_login_ip',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_institution_head' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'institution_id', 'is_active', 'is_institution_head'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    public function isCoordinator(): bool
    {
        return $this->hasRole('coordinador');
    }

    /**
     * El responsable principal de la institución (uno por institución).
     */
    public function isInstitucion(): bool
    {
        return $this->hasRole('institucion');
    }

    /**
     * Personal operativo de la institución (rango menor que 'institucion').
     */
    public function isRepresentante(): bool
    {
        return $this->hasRole('representante');
    }

    /**
     * True si el usuario pertenece a una institución específica (institucion o representante).
     * Estos usuarios tienen acceso restringido a su institución y tipo de datos.
     */
    public function isInstitutionalUser(): bool
    {
        return $this->hasRole(['institucion', 'representante']);
    }

    /**
     * Bypasses PostgreSQL RLS — only admins and coordinadores see all institutions' data.
     */
    public function canBypassRls(): bool
    {
        return $this->hasRole(['admin', 'coordinador']);
    }

    /**
     * Returns the institution type (salud, educacion, desarrollo_social, justicia, otro).
     * Null if user has no institution or is admin/coordinador.
     */
    public function institutionType(): ?string
    {
        return $this->institution?->type;
    }

}
