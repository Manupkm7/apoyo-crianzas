<?php

namespace App\Http\Requests;

use App\Support\ServicePeriod;
use App\Support\Sector;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ChildServiceRequest — carga manual (POST) y corrección (PATCH) de una
 * prestación por período de un niño.
 *
 * institution_id (el efector) solo lo elige el admin; para una institución se
 * fuerza la propia en el controlador, así que acá está prohibido.
 *
 * La regla fina de quién puede la aplica ChildServicePolicy en el controlador.
 */
class ChildServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('ninos.gestionar') || $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        $isPost  = $this->isMethod('post');
        $isAdmin = $this->user()->hasRole('admin');
        $req     = $isPost ? 'required' : 'sometimes';

        return [
            'service_type_id' => [$req, 'uuid', 'exists:service_types,id'],
            'institution_id'  => $isAdmin
                ? [$req, 'uuid', 'exists:institutions,id']
                : ['prohibited'],
            'sector'          => ['sometimes', 'nullable', Rule::in(Sector::keys())],

            'year'            => [$req, 'integer', 'min:2000', 'max:2100'],
            'period_type'     => [$req, Rule::in(ServicePeriod::TYPES)],
            'period_number'   => [$req, 'integer', 'min:1', 'max:6'],

            'service_number'  => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999'],
            'observations'    => ['sometimes', 'nullable', 'string', 'max:3000'],
            'has_alert'       => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $type   = $this->input('period_type');
            $number = $this->input('period_number');

            // En PATCH se valida solo si vienen los dos; el controlador revalida el
            // período combinado con los valores ya guardados.
            if ($type !== null && $number !== null && in_array($type, ServicePeriod::TYPES, true)
                && (int) $number > ServicePeriod::maxNumber($type)) {
                $validator->errors()->add(
                    'period_number',
                    $type === ServicePeriod::TRIMESTRE
                        ? 'El trimestre debe estar entre 1 y 4.'
                        : 'El bimestre debe estar entre 1 y 6.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'service_type_id.required' => 'Elegí la prestación.',
            'service_type_id.exists'   => 'La prestación elegida no existe en el catálogo.',
            'institution_id.required'  => 'Elegí el efector (institución) que brindó la prestación.',
            'institution_id.exists'    => 'La institución elegida no existe.',
            'institution_id.prohibited'=> 'El efector es siempre tu propia institución.',
            'year.required'            => 'Indicá el año.',
            'period_type.required'     => 'Indicá si el período es trimestre o bimestre.',
            'period_type.in'           => 'El período debe ser trimestre o bimestre.',
            'period_number.required'   => 'Indicá el número de período.',
            'sector.in'                => 'El sector no es válido.',
            'observations.max'         => 'Las observaciones no pueden superar los 3000 caracteres.',
        ];
    }
}
