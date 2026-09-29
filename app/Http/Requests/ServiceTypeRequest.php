<?php

namespace App\Http\Requests;

use App\Models\ServiceType;
use App\Support\Sector;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ServiceTypeRequest — alta (POST) y edición (PATCH) de una prestación del
 * catálogo. Solo admin.
 */
class ServiceTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        $req = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name'         => [$req, 'string', 'min:2', 'max:150'],
            'sector'       => [$req, Rule::in(Sector::keys())],
            'description'  => ['sometimes', 'nullable', 'string', 'max:1000'],
            'is_mandatory' => ['sometimes', 'boolean'],
            'is_active'    => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $name = $this->input('name');
            if (! is_string($name) || trim($name) === '') {
                return;
            }

            $current = $this->route('serviceType');

            $taken = ServiceType::where('name_normalized', ServiceType::normalizeName($name))
                ->when($current, fn ($q) => $q->where('id', '!=', $current->id))
                ->exists();

            if ($taken) {
                $validator->errors()->add('name', 'Ya existe una prestación con ese nombre en el catálogo.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'name.required'   => 'Indicá el nombre de la prestación.',
            'sector.required' => 'Indicá el sector de la prestación.',
            'sector.in'       => 'El sector no es válido.',
        ];
    }
}
