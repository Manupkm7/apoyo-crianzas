<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreUserImportRequest — valida la subida de un archivo de carga masiva de
 * usuarios institucionales (rol 'institucion' o 'representante').
 *
 * Quién puede subir:
 *   - Admin ('usuarios.gestionar') y coordinador ('usuarios.carga_masiva'):
 *     cualquier institución, ver SystemActor::hasFullUserImportAccess().
 *   - Responsable de institución ('representantes.gestionar'): solo la propia
 *     (y solo podrá generar representantes — la restricción de rol por fila
 *     se aplica en UserImportRowProcessor, no acá).
 */
class StoreUserImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user->hasFullUserImportAccess()) {
            return true;
        }

        return $user->can('representantes.gestionar')
            && $this->input('institution_id') === $user->institution_id;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:csv,txt,xlsx,xls',
                'max:10240', // 10 MB
            ],
            'institution_id' => [
                'required',
                'uuid',
                Rule::exists('institutions', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required'         => 'Debe adjuntar un archivo CSV, TXT o Excel.',
            'file.mimes'            => 'El archivo debe ser CSV (.csv), TXT (.txt) o Excel (.xlsx, .xls).',
            'file.max'              => 'El archivo no puede superar los 10 MB.',
            'institution_id.required' => 'Debe indicar la institución a la que pertenecerán los usuarios.',
            'institution_id.exists'   => 'La institución seleccionada no existe o está desactivada.',
        ];
    }
}
