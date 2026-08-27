<?php

namespace App\Http\Requests;

use App\Models\Institution;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida la creación de batches a partir de un archivo ya subido vía
 * ImportController::preview() (que devolvió 'storage_path' y la lista de hojas).
 *
 * Cada entrada de 'sheets' se convierte en un ImportBatch propio, con su
 * propia fuente e institución — así un mismo archivo Excel con varias hojas
 * (una por institución/efector) puede repartirse entre distintas fuentes.
 */
class StoreImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('importaciones.gestionar');
    }

    public function rules(): array
    {
        return [
            'storage_path'       => ['required', 'string'],
            'original_filename'  => ['required', 'string', 'max:255'],

            'sheets'             => ['required', 'array', 'min:1'],
            'sheets.*.sheet_name'     => ['nullable', 'string', 'max:120'],
            'sheets.*.source'         => ['required', 'in:civil_registry,education,health'],
            'sheets.*.institution_id' => ['nullable', 'uuid', 'exists:institutions,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'storage_path.required'      => 'Falta el archivo (subilo de nuevo).',
            'sheets.required'            => 'Debe indicar al menos una hoja a procesar.',
            'sheets.*.source.required'   => 'Debe indicar la fuente de cada hoja.',
            'sheets.*.source.in'         => 'La fuente debe ser "civil_registry", "education" o "health".',
            'sheets.*.institution_id.exists' => 'La institución seleccionada no existe en el sistema.',
        ];
    }

    /**
     * required_if por índice: 'education'/'health' necesitan institución (del tipo
     * correspondiente), 'civil_registry' no. No se puede expresar con reglas
     * declarativas simples sobre un array de objetos.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $sheets = $this->input('sheets', []);
            $institutionTypeBySource = ['education' => 'educacion', 'health' => 'salud'];

            foreach ($sheets as $index => $sheet) {
                $source = $sheet['source'] ?? null;
                $expectedType = $institutionTypeBySource[$source] ?? null;

                if ($expectedType === null) {
                    continue;
                }

                if (empty($sheet['institution_id'])) {
                    $validator->errors()->add(
                        "sheets.{$index}.institution_id",
                        "Para importaciones de {$source} debe seleccionar la institución correspondiente."
                    );
                    continue;
                }

                $institution = Institution::find($sheet['institution_id']);
                if ($institution && $institution->type !== $expectedType) {
                    $validator->errors()->add(
                        "sheets.{$index}.institution_id",
                        "La institución seleccionada no es de tipo \"{$expectedType}\"."
                    );
                }
            }
        });
    }
}
