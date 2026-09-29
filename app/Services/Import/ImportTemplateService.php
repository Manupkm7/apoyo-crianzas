<?php

namespace App\Services\Import;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Genera la plantilla descargable (XLSX, CSV o TXT) que le indica al usuario
 * qué columnas se esperan para cada fuente de importación.
 *
 * Las etiquetas en español de cada campo se eligen para que coincidan
 * literalmente con una de las variantes que ya reconoce
 * ImportParserService::buildFieldMap() — así una plantilla completada y
 * resubida siempre matchea sin que el usuario tenga que adivinar nombres
 * de campo en inglés.
 */
class ImportTemplateService
{
    private const CIVIL_REGISTRY_FIELDS = [
        ['label' => 'Nombre',                 'required' => true,  'example' => 'Juan',                'help' => 'Nombre del niño o niña.'],
        ['label' => 'Apellido',               'required' => true,  'example' => 'Pérez',                'help' => 'Apellido del niño o niña.'],
        ['label' => 'Fecha de nacimiento',    'required' => true,  'example' => '15/03/2020',           'help' => 'Formato DD/MM/AAAA.'],
        ['label' => 'Nombre de la madre',     'required' => false, 'example' => 'Ana Gómez',            'help' => 'Nombre y apellido de la madre.'],
        ['label' => 'DNI de la madre',        'required' => false, 'example' => '30111222',             'help' => 'Sin puntos ni espacios.'],
        ['label' => 'Nombre del padre',       'required' => false, 'example' => 'Carlos Pérez',         'help' => 'Nombre y apellido del padre.'],
        ['label' => 'DNI del padre',          'required' => false, 'example' => '28999111',             'help' => 'Sin puntos ni espacios.'],
        ['label' => 'Domicilio',              'required' => false, 'example' => 'Belgrano 123',         'help' => 'Domicilio familiar.'],
        ['label' => 'Establecimiento',        'required' => false, 'example' => 'Hospital Municipal',   'help' => 'Establecimiento donde ocurrió el nacimiento.'],
    ];

    private const EDUCATION_FIELDS = [
        ['label' => 'Nombre',                 'required' => true,  'example' => 'Juan',                'help' => 'Nombre del alumno o alumna.'],
        ['label' => 'Apellido',               'required' => true,  'example' => 'Pérez',                'help' => 'Apellido del alumno o alumna.'],
        ['label' => 'Fecha de nacimiento',    'required' => true,  'example' => '15/03/2020',           'help' => 'Formato DD/MM/AAAA.'],
        ['label' => 'DNI',                    'required' => false, 'example' => '41222333',             'help' => 'Sin puntos ni espacios.'],
        ['label' => 'Escuela',                'required' => true,  'example' => 'Escuela N°5',          'help' => 'Nombre del establecimiento educativo.'],
        ['label' => 'Grado',                  'required' => false, 'example' => '3° grado',             'help' => 'Grado, año, sala o sección.'],
    ];

    private const HEALTH_FIELDS = [
        ['label' => 'Nombre',                 'required' => true,  'example' => 'Juan',                'help' => 'Nombre del niño o niña.'],
        ['label' => 'Apellido',               'required' => true,  'example' => 'Pérez',                'help' => 'Apellido del niño o niña.'],
        ['label' => 'Fecha de nacimiento',    'required' => true,  'example' => '15/03/2020',           'help' => 'Formato DD/MM/AAAA.'],
        ['label' => 'DNI',                    'required' => false, 'example' => '41222333',             'help' => 'Sin puntos ni espacios.'],
        ['label' => 'Centro de salud',        'required' => true,  'example' => 'CAPS N°3',              'help' => 'Salita, hospital o centro de salud.'],
        ['label' => 'Control sano al día',    'required' => false, 'example' => 'Sí',                   'help' => 'Sí / No. Dejar vacío si no se sabe.'],
        ['label' => 'Vacunas al día',         'required' => false, 'example' => 'Sí',                   'help' => 'Sí / No. Dejar vacío si no se sabe.'],
        ['label' => 'Fecha último control',   'required' => false, 'example' => '10/06/2025',           'help' => 'Formato DD/MM/AAAA.'],
        ['label' => 'Observaciones',          'required' => false, 'example' => '',                     'help' => 'Notas adicionales, opcional.'],
    ];

    /**
     * Hoja de prestaciones: una fila por prestación, cada una con su efector (el
     * mismo niño puede repetirse). Pasa por el mismo flujo de matcheo de niños.
     */
    private const SERVICES_FIELDS = [
        ['label' => 'Fecha',             'required' => true,  'example' => 'TRIM12026',            'help' => 'Período informado: TRIM + número + año (TRIM12026 = trimestre 1 de 2026) o BIM + número + año (BIM32026).'],
        ['label' => 'DNI',               'required' => false, 'example' => '41222333',             'help' => 'DNI del niño o niña, sin puntos ni espacios. Muy recomendable: es la señal más confiable para identificarlo.'],
        ['label' => 'Niño',              'required' => true,  'example' => 'María Perez',          'help' => 'Nombre y apellido (o "Apellido, Nombre"). También se aceptan columnas separadas "Nombre" y "Apellido".'],
        ['label' => 'Nro prestación',    'required' => false, 'example' => '1',                    'help' => 'Número de la prestación dentro del registro del organismo.'],
        ['label' => 'Sector',            'required' => true,  'example' => 'Salud',                'help' => 'Salud / Cuidado y educación / Protección social / Recreación, deporte y cultura / Otro.'],
        ['label' => 'Efector',           'required' => true,  'example' => 'CAP 1',                'help' => 'Nombre EXACTO de la institución (efector) tal como está dada de alta en el sistema. Si es de Salud o de Cuidado y educación, el niño queda asociado a ella (si no tenía institución en ese sector).'],
        ['label' => 'Nombre prestación', 'required' => true,  'example' => 'Control de niño sano', 'help' => 'Si no existe en el catálogo de prestaciones, se agrega al confirmar la fila.'],
        ['label' => 'Observaciones',     'required' => false, 'example' => 's/d',                  'help' => 'Texto libre. "s/d" = sin dato.'],
        ['label' => 'Alerta',            'required' => false, 'example' => 'NO',                   'help' => 'SI / NO. Con SI, la prestación genera una alerta en el sistema.'],
    ];

    private const USER_FIELDS = [
        ['label' => 'ID_NOMBRE',   'required' => true, 'example' => 'Juan',        'help' => 'Nombre de la persona.'],
        ['label' => 'ID_APELLIDO','required' => true, 'example' => 'Pérez',        'help' => 'Apellido de la persona.'],
        ['label' => 'ID_DNI',      'required' => true, 'example' => '42544839',    'help' => 'Siempre 8 dígitos, sin puntos ni espacios.'],
        ['label' => 'ROL',         'required' => true, 'example' => 'Representante', 'help' => 'Institución (responsable/director) o Representante (personal de la institución).'],
    ];

    private const SOURCE_LABELS = [
        'civil_registry' => 'Registro Civil',
        'education'      => 'Educación',
        'health'         => 'Salud',
        'services'       => 'Prestaciones',
        'users'          => 'Usuarios',
    ];

    private const SOURCE_FILENAMES = [
        'civil_registry' => 'registro_civil',
        'education'      => 'educacion',
        'health'         => 'salud',
        'services'       => 'prestaciones',
        'users'          => 'usuarios',
    ];

    public static function isValidSource(string $source): bool
    {
        return array_key_exists($source, self::SOURCE_LABELS);
    }

    public function filenameStem(string $source): string
    {
        return 'plantilla_' . self::SOURCE_FILENAMES[$source];
    }

    /**
     * @return array<int, array{label: string, required: bool, example: string, help: string}>
     */
    private function fields(string $source): array
    {
        return match ($source) {
            'civil_registry' => self::CIVIL_REGISTRY_FIELDS,
            'users'          => self::USER_FIELDS,
            'health'         => self::HEALTH_FIELDS,
            'services'       => self::SERVICES_FIELDS,
            default          => self::EDUCATION_FIELDS,
        };
    }

    /**
     * Cabecera + fila de ejemplo delimitadas, para CSV (',') o TXT liviano ('|').
     */
    public function buildDelimited(string $source, string $delimiter): string
    {
        $fields = $this->fields($source);

        $headers = array_map(fn (array $f) => $f['label'], $fields);
        $example = array_map(fn (array $f) => $f['example'], $fields);

        $lines = [
            implode($delimiter, $headers),
            implode($delimiter, $example),
        ];

        // BOM UTF-8 para que Excel abra bien los acentos al abrir el CSV directo.
        return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Hoja "Plantilla" (cabeceras + ejemplo) + hoja "Instrucciones" (campo,
     * obligatorio, descripción), con el mismo estilo visual que el resto de
     * los exports del sistema (negrita, autosize, freeze pane).
     */
    public function buildSpreadsheet(string $source): Spreadsheet
    {
        $fields = $this->fields($source);

        $spreadsheet = new Spreadsheet();

        $headers = array_map(fn (array $f) => $f['label'], $fields);
        $example = array_map(fn (array $f) => $f['example'], $fields);
        $this->addSheet($spreadsheet, 0, 'Plantilla', $headers, [$example]);

        $instructions = array_map(fn (array $f) => [
            $f['label'],
            $f['required'] ? 'Sí' : 'No',
            $f['help'],
        ], $fields);
        $this->addSheet(
            $spreadsheet,
            1,
            'Instrucciones',
            ['Columna', 'Obligatoria', 'Descripción'],
            $instructions
        );

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function addSheet(Spreadsheet $spreadsheet, int $index, string $title, array $headers, array $rows): void
    {
        /** @var Worksheet $sheet */
        $sheet = $index === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
        $sheet->setTitle($title);

        $sheet->fromArray($headers, null, 'A1');
        if ($rows !== []) {
            $sheet->fromArray($rows, null, 'A2');
        }

        $highestColumn = $sheet->getHighestColumn();
        $sheet->getStyle("A1:{$highestColumn}1")->getFont()->setBold(true);
        foreach (range('A', $highestColumn) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->freezePane('A2');
    }
}
