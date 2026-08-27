<?php

namespace App\Models;

use App\Contracts\SystemActor;
use App\Models\Concerns\HasInstitutionalRoleChecks;
use App\Models\Concerns\HasLoginLockout;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

/*
 * Institución municipal del sistema.
 *
 * Cada institución tiene un tipo (salud, educacion, desarrollo_social, etc.)
 * que determina qué módulos de datos podrán usar sus usuarios.
 * Los cambios en nombre, tipo y estado activo quedan registrados automáticamente
 * en el historial de auditoría.
 *
 * Desde el login institucional (provincia → departamento → localidad → sector
 * → institución), la Institution es autenticable por sí misma con su propia
 * contraseña, además de que sus User (representantes, cabeza de institución)
 * sigan logueando individualmente como siempre. Extiende el mismo
 * Authenticatable genérico de Laravel que usa User — no depende de él.
 */

class Institution extends Authenticatable implements SystemActor
{
    use HasApiTokens, HasFactory, HasInstitutionalRoleChecks, HasLoginLockout, HasRoles, HasUuids, LogsActivity, SoftDeletes;

    // Spatie Permission necesita saber que este modelo usa el guard 'sanctum'.
    protected $guard_name = 'sanctum';

    // Sala máxima fija de jardín maternal e infantil (no varía por institución).
    public const JARDIN_MAX_GRADE = 5;

    protected $fillable = [
        'name',
        'type',
        'address',
        'phone',
        'locality_id',
        'is_active',
        'offers_jardin',
        'offers_primario',
        'primario_years',
        'offers_secundario',
        'secundario_years',
        'created_by',
        'updated_by',

        // Login institucional y bloqueo por intentos fallidos. Los setean
        // InstitutionController::store / ::resetPassword y el trait HasLoginLockout
        // vía asignación masiva; sin estar acá Laravel los descartaba en silencio
        // y la contraseña de la institución nunca se persistía (quedaba NULL).
        // No se exponen por PATCH /institutions: UpdateInstitutionRequest no los
        // valida y el controller usa solo $request->validated().
        'password',
        'password_must_change',
        'failed_login_attempts',
        'locked_until',
        'last_login_at',
        'last_login_ip',
    ];

    protected $hidden = [
        'password',
        'failed_login_attempts',
        'locked_until',
        'last_login_ip',
    ];

    protected static function booted(): void
    {
        // Toda institución actúa con el mismo nivel de acceso que un
        // responsable de institución cuando se loguea con su propia password.
        static::created(function (self $institution) {
            $institution->assignRole('institucion');
        });
    }

    protected function casts(): array
    {
        return [
            'is_active'         => 'boolean',
            'offers_jardin'     => 'boolean',
            'offers_primario'   => 'boolean',
            'primario_years'    => 'integer',
            'offers_secundario' => 'boolean',
            'secundario_years'  => 'integer',
            'password'          => 'hashed',
            'password_must_change' => 'boolean',
            'last_login_at'     => 'datetime',
            'locked_until'      => 'datetime',
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

    public function locality(): BelongsTo
    {
        return $this->belongsTo(Locality::class);
    }

    /**
     * Cuando el actor autenticado es la propia Institution, su "institution_id"
     * es su propio id. Este accessor es lo que permite que las Policies, el
     * middleware de RLS y los controllers que hoy leen $user->institution_id
     * sigan funcionando sin cambios sea cual sea el tipo de actor autenticado.
     */
    public function getInstitutionIdAttribute(): string
    {
        return $this->id;
    }

    /**
     * Cuando el actor autenticado es la propia Institution, "su" institución es
     * ella misma. Igual que getInstitutionIdAttribute(), esto deja que los
     * controllers, requests y policies que leen $user->institution (ChildController,
     * StoreEducationRecordRequest, etc.) funcionen sin ramificar por tipo de actor.
     * Es un accessor, no una relación: no se puede eager-loadear ni aparece en el
     * JSON (no está en $appends).
     */
    public function getInstitutionAttribute(): self
    {
        return $this;
    }

    /**
     * Una Institution no está en la tabla users, así que no puede ir en las
     * columnas created_by / updated_by (FK a users). Su autoría queda en
     * activity_log como causer polimórfico.
     */
    public function auditId(): ?string
    {
        return null;
    }

    public function institutionType(): ?string
    {
        return $this->type;
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
