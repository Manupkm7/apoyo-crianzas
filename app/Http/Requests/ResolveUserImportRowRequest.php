<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveUserImportRowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasFullUserImportAccess()
            || $this->user()->can('representantes.gestionar');
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(['confirm', 'skip'])],
        ];
    }

    public function messages(): array
    {
        return [
            'action.required' => 'Debe indicar la acción (confirm o skip).',
            'action.in'       => 'La acción debe ser "confirm" o "skip".',
        ];
    }
}
