<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UserResource — Controla qué datos de un usuario se envían al frontend.
 *
 * IMPORTANTE: Este recurso NUNCA debe incluir campos sensibles como:
 * - password (contraseña cifrada)
 * - remember_token (token de "recordarme")
 * - failed_login_attempts (intentos fallidos)
 * - locked_until (bloqueo de cuenta)
 * - last_login_ip (IP del último acceso)
 *
 * Estos campos están también en $hidden del modelo User, pero lo declaramos
 * explícitamente aquí como segunda línea de defensa.
 */
class UserResource extends JsonResource
{
    /**
     * Convierte el modelo User en un array JSON para la respuesta.
     *
     * Los permisos solo se incluyen cuando el usuario consulta su propio perfil
     * o cuando un admin consulta a otro usuario. En listados paginados se omiten
     * para reducir el tamaño de la respuesta.
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'name'                => $this->name,
            'email'               => $this->email,
            'is_active'           => $this->is_active,
            'is_institution_head' => $this->is_institution_head,

            // El rol del usuario (ej: "admin", "representante")
            // whenLoaded evita una consulta extra si la relación no fue cargada
            'roles'               => $this->whenLoaded('roles', fn () =>
                $this->roles->pluck('name')
            ),

            // Los permisos solo se incluyen si fueron cargados explícitamente,
            // para no sobrecargar los listados con datos innecesarios
            'permissions'         => $this->when(
                $request->routeIs('users.show') || $request->routeIs('me'),
                fn () => $this->getAllPermissions()->pluck('name')
            ),

            // Datos de la institución a la que pertenece el usuario
            'institution'         => $this->whenLoaded('institution', fn () =>
                new InstitutionResource($this->institution)
            ),

            'last_login_at'       => $this->last_login_at?->toISOString(),
            'created_at'          => $this->created_at?->toISOString(),
        ];
    }
}
