<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UserImportRowResource — datos de una fila de importación de usuarios para
 * la cola de revisión manual.
 *
 * Descifra raw_data para mostrar nombre/apellido/DNI tal como vinieron del
 * archivo. El DNI se muestra completo (a diferencia de ImportRowResource,
 * que nunca expone DNIs): acá el operador lo necesita para poder ubicar la
 * cuenta existente cuando el motivo es un duplicado, y ya tiene permiso de
 * gestión de usuarios para acceder a este endpoint.
 */
class UserImportRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $raw = json_decode($this->raw_data ?? '{}', true) ?: [];

        return [
            'id'                => $this->id,
            'status'            => $this->status,

            'first_name' => $raw['first_name'] ?? null,
            'last_name'  => $raw['last_name'] ?? null,
            'dni'        => $raw['dni'] ?? null,
            'role'       => $raw['role'] ?? null,

            'review_reason'     => $this->review_reason,
            'notes'             => $this->notes,
            'file_line_number'  => $this->file_line_number,

            'created_user' => $this->whenLoaded('createdUser', fn () =>
                $this->createdUser ? new UserResource($this->createdUser) : null
            ),

            'resolved_by' => $this->when($this->resolved_at !== null, fn () => [
                'user_id'     => $this->resolved_by,
                'resolved_at' => $this->resolved_at?->toISOString(),
            ]),

            'error_message' => $this->when($this->status === 'error', $this->error_message),
        ];
    }
}
