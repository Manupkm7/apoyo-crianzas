<?php

namespace App\Contracts;

/*
 * Contrato común para cualquier principal que pueda autenticarse y ser
 * evaluado por las Policies del sistema: hoy User (persona) e Institution
 * (login institucional). Permite que las Policies tipen el actor autenticado
 * sin acoplarse a un único modelo Eloquent concreto.
 *
 * No declara hasRole()/can(): ambos modelos los heredan de Spatie HasRoles /
 * Illuminate Authorizable con firmas propias del framework; PHP no exige que
 * una interfaz declare todo método invocado dinámicamente sobre el objeto.
 */
interface SystemActor
{
    /**
     * Id para las columnas de auditoría created_by / updated_by, que son FK a
     * la tabla users. Un User devuelve su id; una Institution devuelve null
     * (no está en users — su autoría queda registrada en activity_log como
     * causer polimórfico, ver config/activitylog.php auth_driver = 'sanctum').
     */
    public function auditId(): ?string;

    public function isAdmin(): bool;

    public function isCoordinator(): bool;

    public function isInstitucion(): bool;

    public function isRepresentante(): bool;

    public function isInstitutionalUser(): bool;

    public function canBypassRls(): bool;

    public function hasFullUserImportAccess(): bool;

    public function institutionType(): ?string;
}
