<?php

namespace App\Services\Import;

use App\Models\User;
use App\Models\UserImportRow;
use Illuminate\Support\Str;

/**
 * Valida una fila de importación de usuarios (institución/representante) y,
 * si no hay conflictos, crea la cuenta.
 *
 * Es la ÚNICA implementación de "intentar resolver esta fila a un usuario
 * creado": la reutilizan tanto el job de procesamiento automático
 * (ProcessUserImportBatch) como la resolución manual de una fila en revisión
 * (UserImportController::resolveRow, acción "confirm"). Así, confirmar una
 * fila corre exactamente la misma validación que el alta automática — si el
 * conflicto que la mandó a revisión ya no existe (ej. el admin desactivó al
 * responsable anterior), confirmar la crea; si sigue existiendo, vuelve a
 * fallar con el mismo motivo.
 *
 * Roles permitidos por este import: SOLO 'institucion' y 'representante'.
 * 'admin'/'coordinador' nunca se pueden asignar desde un archivo — sería una
 * vía de escalamiento de privilegios subiendo un CSV.
 */
class UserImportRowProcessor
{
    private const ALLOWED_ROLES = ['institucion', 'representante'];

    // Claves ya normalizadas (minúsculas, sin acentos) — normalizeRoleKey() aplica
    // Str::ascii() antes de buscar acá, así que no hace falta una variante con tilde.
    private const ROLE_SYNONYMS = [
        'institucion'  => 'institucion',
        'director'     => 'institucion',
        'directora'    => 'institucion',
        'rector'       => 'institucion',
        'rectora'      => 'institucion',
        'responsable'  => 'institucion',
        'representante' => 'representante',
        'personal'     => 'representante',
        'staff'        => 'representante',
        'empleado'     => 'representante',
        'profesional'  => 'representante',
    ];

    /**
     * @param array $rowData          Datos ya mapeados por ImportParserService (first_name, last_name, dni, role)
     * @param string $institutionId   Institución del batch — todos los usuarios creados pertenecen a ella
     * @param string|null $uploaderId Quién subió el archivo (created_by del usuario nuevo); null si fue una Institution
     * @param array $rolesAllowedForUploader  Roles que el uploader tiene permiso de asignar (subconjunto de ALLOWED_ROLES)
     */
    public function process(
        array $rowData,
        string $institutionId,
        ?string $uploaderId,
        array $rolesAllowedForUploader,
    ): RowProcessResult {
        $firstName = trim((string) ($rowData['first_name'] ?? ''));
        $lastName  = trim((string) ($rowData['last_name'] ?? ''));
        $rawDni    = (string) ($rowData['dni'] ?? '');
        $rawRole   = (string) ($rowData['role'] ?? '');

        if ($firstName === '' || $lastName === '') {
            return RowProcessResult::needsReview(
                'missing_name',
                'Falta el nombre o el apellido.',
            );
        }

        $dni = User::normalizeDni($rawDni);
        if (strlen($dni) !== 8) {
            return RowProcessResult::needsReview(
                'invalid_dni',
                "El DNI \"{$rawDni}\" no tiene 8 dígitos.",
            );
        }

        $role = self::ROLE_SYNONYMS[$this->normalizeRoleKey($rawRole)] ?? null;
        if ($role === null || ! in_array($role, self::ALLOWED_ROLES, true)) {
            return RowProcessResult::needsReview(
                'invalid_role',
                "El rol \"{$rawRole}\" no es válido. Debe ser Institución o Representante.",
            );
        }
        if (! in_array($role, $rolesAllowedForUploader, true)) {
            return RowProcessResult::needsReview(
                'invalid_role',
                "Quien sube el archivo no tiene permiso para asignar el rol \"{$role}\".",
                null,
                $role,
            );
        }

        $dniHash = User::dniHash($dni);

        if (User::where('dni_hash', $dniHash)->whereNull('deleted_at')->exists()) {
            return RowProcessResult::needsReview(
                'duplicate_dni_existing',
                'Ya existe un usuario con este DNI en el sistema.',
                $dniHash,
                $role,
            );
        }

        if (UserImportRow::where('dni_hash', $dniHash)->where('status', 'created')->exists()) {
            return RowProcessResult::needsReview(
                'duplicate_dni_in_file',
                'Otra fila de este mismo archivo ya creó un usuario con este DNI.',
                $dniHash,
                $role,
            );
        }

        if ($role === 'institucion' && User::hasActiveInstitutionHead($institutionId)) {
            return RowProcessResult::needsReview(
                'institution_head_conflict',
                'Esta institución ya tiene un responsable activo.',
                $dniHash,
                $role,
            );
        }

        $user = User::create([
            'name'                => "{$firstName} {$lastName}",
            'email'               => $this->buildPlaceholderEmail($firstName, $lastName, $dni),
            'password'            => Str::password(24), // aleatoria y desconocida — la cuenta arranca inactiva
            'dni'                 => $dni,
            'dni_hash'            => $dniHash,
            'institution_id'      => $institutionId,
            'is_active'           => false, // provisoria: el admin debe completarla antes de habilitarla
            'is_institution_head' => $role === 'institucion',
            'created_by'          => $uploaderId,
        ]);
        $user->assignRole($role);

        return RowProcessResult::created($user->id, $dniHash, $role);
    }

    private function normalizeRoleKey(string $role): string
    {
        return Str::of($role)->trim()->lower()->ascii()->toString();
    }

    /**
     * nombre.apellido.dni@pendiente.local — cada parte en minúsculas, sin
     * acentos ni espacios. El DNI como sufijo evita colisiones entre
     * homónimos (el email es único a nivel de negocio gracias al DNI).
     */
    private function buildPlaceholderEmail(string $firstName, string $lastName, string $dni): string
    {
        return $this->slug($firstName) . '.' . $this->slug($lastName) . '.' . $dni . '@pendiente.local';
    }

    private function slug(string $value): string
    {
        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString();
    }
}
