<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('importaciones.gestionar');
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:csv,txt,xlsx,xls',
                'max:10240', // 10 MB — límite razonable para un archivo de municipio
            ],
            'source' => ['required', 'in:civil_registry,education'],

            // Obligatorio cuando source=education (indica la escuela del sistema).
            // Opcional para civil_registry (fuente externa sin institución vinculada).
            'institution_id' => [
                'nullable',
                'uuid',
                'exists:institutions,id',
                'required_if:source,education',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required'              => 'Debe adjuntar un archivo CSV o Excel.',
            'file.mimes'                 => 'El archivo debe ser CSV (.csv) o Excel (.xlsx, .xls).',
            'file.max'                   => 'El archivo no puede superar los 10 MB.',
            'source.required'            => 'Debe indicar la fuente del archivo (registro civil o educación).',
            'source.in'                  => 'La fuente debe ser "civil_registry" o "education".',
            'institution_id.required_if' => 'Para importaciones de educación debe seleccionar la institución correspondiente.',
            'institution_id.exists'      => 'La institución seleccionada no existe en el sistema.',
        ];
    }
}