<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * StoreHealthObservationRequest — Valida una nueva entrada de observación de salud.
 *
 * Solo la institución de salud dueña del registro puede agregar observaciones
 * (misma regla que editar el registro de salud — ver HealthRecordPolicy::update).
 * Representantes NO pueden agregar observaciones (solo la institución).
 */
class StoreHealthObservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('ninos.gestionar') || $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        return [
            'body'       => ['required', 'string', 'max:3000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf', 'max:10240'], // 10 MB
        ];
    }

    public function messages(): array
    {
        return [
            'body.required'    => 'La observación no puede estar vacía.',
            'attachment.mimes' => 'El adjunto debe ser un archivo PDF.',
            'attachment.max'   => 'El adjunto no puede superar los 10 MB.',
        ];
    }
}
