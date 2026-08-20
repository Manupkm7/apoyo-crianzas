<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * InstitutionDirectoryResource — Listado público (sin autenticar) de
 * instituciones para el desplegable del login institucional.
 *
 * A propósito expone solo id/name/type: nunca password, contacto ni
 * ningún otro dato — este endpoint es de acceso público.
 */
class InstitutionDirectoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'   => $this->id,
            'name' => $this->name,
            'type' => $this->type,
        ];
    }
}
