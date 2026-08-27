<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * AcknowledgeAlertRequest — Valida el marcado de una alerta del SAT como
 * "gestionada / en seguimiento".
 *
 * La regla fina de quién puede ("institución dueña del registro del sector o
 * admin") la aplica el controlador con Education/HealthRecordPolicy::update
 * sobre el registro correspondiente. Acá solo exigimos el permiso general de
 * gestión de niños.
 */
class AcknowledgeAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('ninos.gestionar') || $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'note.required' => 'Contá qué se hizo o se va a hacer con esta alerta.',
            'note.min'      => 'La nota es demasiado corta.',
            'note.max'      => 'La nota no puede superar los 2000 caracteres.',
        ];
    }
}
