<?php

namespace App\Models;

use App\Contracts\SystemActor;
use App\Models\Concerns\HasInstitutionalRoleChecks;
use App\Models\Concerns\HasLoginLockout;
use App\Services\Import\ImportMatchingService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements SystemActor
{
    use HasApiTokens, HasFactory, HasInstitutionalRoleChecks, HasLoginLockout, HasRoles, HasUuids, LogsActivity, Notifiable, SoftDeletes;

    // Spatie Permission necesita saber que este modelo usa el guard 'sanctum'.
    // Sin esto, syncRoles() y hasRole() buscan los roles con guard 'web' y fallan.
    protected $guard_name = 'sanctum';

    protected $fillable = [
        'name',
        'email',
        'password',
        'dni',
        'dni_hash',
        'institution_id',
        'is_active',
        'is_institution_head',
        'created_by',
        'updated_by',

        // Bloqueo por intentos fallidos y registro de acceso. AuthController y el
        // trait HasLoginLockout los setean vía $user->update([...]); sin estar en
        // $fillable, Laravel los descartaba en silencio y el bloqueo de cuenta
        // nunca llegaba a persistirse. No se exponen por la API: Store/UpdateUserRequest
        // no los validan y los controllers usan $request->validated().
        'failed_login_attempts',
        'locked_until',
        'last_login_at',
        'last_login_ip',
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
            'dni' => 'encrypted',
            'is_active' => 'boolean',
            'is_institution_head' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        // dni y dni_hash nunca aparecen en el historial de auditoría (mismo criterio
        // que birth_records: ningún DNI queda expuesto en el audit log).
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'institution_id', 'is_active', 'is_institution_head'])
            ->logOnlyDirty();
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * Id para columnas de auditoría created_by / updated_by (FK a users).
     */
    public function auditId(): ?string
    {
        return $this->id;
    }

    /**
     * Returns the institution type (salud, educacion, desarrollo_social, justicia, otro).
     * Null if user has no institution or is admin/coordinador.
     */
    public function institutionType(): ?string
    {
        return $this->institution?->type;
    }

    /**
     * True si la institución ya tiene un responsable ('institucion') activo.
     * Usado antes de asignar ese rol (alta manual o carga masiva) para no violar
     * la unicidad de un único responsable por institución.
     *
     * @param string $excludeUserId Id a excluir de la verificación (para permitir
     *                              "reasignar" el mismo rol al usuario que ya lo tiene)
     */
    public static function hasActiveInstitutionHead(string $institutionId, ?string $excludeUserId = null): bool
    {
        return static::where('institution_id', $institutionId)
            ->where('is_institution_head', true)
            ->when($excludeUserId, fn ($q) => $q->where('id', '!=', $excludeUserId))
            ->whereNull('deleted_at')
            ->exists();
    }

    /**
     * Normaliza un DNI crudo (puede venir con puntos, espacios o guiones) a solo
     * dígitos. El DNI argentino siempre tiene 8 dígitos — la validación de
     * longitud vive en las reglas de request correspondientes, no acá.
     */
    public static function normalizeDni(string $rawDni): string
    {
        return preg_replace('/\D/', '', $rawDni) ?? '';
    }

    /**
     * SHA-256 de un DNI ya normalizado (solo dígitos), para buscar/detectar
     * duplicados sin descifrar la columna `dni` (cast 'encrypted', IV aleatorio).
     * Reutiliza ImportMatchingService::hashDni() — el mismo algoritmo que ya usa
     * el hash de mother_dni/father_dni en birth_records.
     */
    public static function dniHash(string $normalizedDni): string
    {
        return ImportMatchingService::hashDni($normalizedDni);
    }

    /**
     * True si el actor tiene acceso "completo" a la carga masiva de usuarios:
     * cualquier institución, y puede generar filas con rol 'institucion' o
     * 'representante'. Lo tienen admin ('usuarios.gestionar') y, como
     * excepción puntual, coordinador ('usuarios.carga_masiva') — sin que
     * coordinador gane el ABM individual de usuarios que da 'usuarios.gestionar'.
     *
     * Quien NO tenga esto (el responsable de institución, vía
     * 'representantes.gestionar') solo puede subir para su propia institución
     * y solo generar representantes — ver UserImportController::rolesAllowedFor().
     *
     * La implementación vive en HasInstitutionalRoleChecks para que también
     * aplique al actor Institution (login institucional).
     */
}
