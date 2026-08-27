<?php

namespace App\Models\Concerns;

/*
 * Chequeos de rol compartidos entre cualquier actor autenticable del sistema
 * (User, Institution). Se apoya en hasRole() de Spatie HasRoles, que ambos
 * modelos usan con guard_name = 'sanctum'.
 */
trait HasInstitutionalRoleChecks
{
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
     * True si el actor pertenece a una institución específica (institucion o representante).
     * Estos actores tienen acceso restringido a su institución y tipo de datos.
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
     * Carga masiva de usuarios "completa": puede subir para cualquier institución
     * y generar filas con rol 'institucion' o 'representante'. Lo tienen admin
     * ('usuarios.gestionar') y coordinador ('usuarios.carga_masiva'). Quien no lo
     * tenga (responsable de institución / actor Institution, vía
     * 'representantes.gestionar') solo sube para su propia institución y solo
     * genera representantes — ver UserImportController::rolesAllowedFor().
     */
    public function hasFullUserImportAccess(): bool
    {
        return $this->can('usuarios.gestionar') || $this->can('usuarios.carga_masiva');
    }
}
