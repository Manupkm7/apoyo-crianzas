<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PreviewImportRequest extends FormRequest
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
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Debe adjuntar un archivo CSV o Excel.',
            'file.mimes'     => 'El archivo debe ser CSV (.csv) o Excel (.xlsx, .xls).',
            'file.max'       => 'El archivo no puede superar los 10 MB.',
        ];
    }
}
