<?php

namespace App\Services\Import;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * Parsea archivos CSV y XLSX de importación y los convierte a arrays normalizados.
 *
 * Cada fuente (registro civil, educación) tiene un mapa de variantes de nombres
 * de columna para tolerar las distintas nomenclaturas que pueden usar los organismos.
 * La comparación de cabeceras es case-insensitive y strip de espacios/guiones.
 *
 * Retorna un generator (yield) para no cargar todo el archivo en memoria:
 * útil para archivos grandes de miles de registros.
 *
 * Dependencia: maatwebsite/excel (wraps PhpSpreadsheet).
 * Instalar con: composer require maatwebsite/excel
 */
class ImportParserService
{
    // ─── Mapas de columnas por fuente ─────────────────────────────────────────────

    private const CIVIL_REGISTRY_COLUMNS = [
        'first_name' => [
            'nombre', 'nombres', 'primer nombre', 'primer_nombre', 'first_name', 'nombre del niño', 'nombre niño',
        ],
        'last_name' => [
            'apellido', 'apellidos', 'last_name', 'apellido del niño', 'apellido niño',
        ],
        'birth_date' => [
            'fecha nacimiento', 'fecha_nacimiento', 'fec nac', 'fec_nac', 'birth_date', 'fecha de nacimiento', 'fnac',
        ],
        'mother_name' => [
            'nombre madre', 'nombre de la madre', 'madre', 'madre nombre', 'nombre_madre', 'progenitora',
        ],
        'mother_dni' => [
            'dni madre', 'dni de la madre', 'madre dni', 'dni_madre', 'documento madre', 'doc madre', 'dnm',
        ],
        'father_name' => [
            'nombre padre', 'nombre del padre', 'padre', 'padre nombre', 'nombre_padre', 'progenitor',
        ],
        'father_dni' => [
            'dni padre', 'dni del padre', 'padre dni', 'dni_padre', 'documento padre', 'doc padre', 'dnp',
        ],
        'address' => [
            'domicilio', 'dirección', 'direccion', 'address', 'domicilio familiar', 'calle',
        ],
        'birth_establishment' => [
            'establecimiento', 'establecimiento nacimiento', 'lugar nacimiento', 'hospital', 'maternidad',
            'establecimiento_nacimiento', 'efector', 'lugar de nacimiento',
        ],
    ];

    private const EDUCATION_COLUMNS = [
        'first_name' => [
            'nombre', 'nombres', 'primer nombre', 'primer_nombre', 'first_name', 'nombre del alumno', 'nombre alumno',
        ],
        'last_name' => [
            'apellido', 'apellidos', 'last_name', 'apellido del alumno',
        ],
        'birth_date' => [
            'fecha nacimiento', 'fecha_nacimiento', 'fec nac', 'fec_nac', 'birth_date', 'fecha de nacimiento', 'fnac',
        ],
        'dni' => [
            'dni', 'documento', 'documento identidad', 'documento_identidad', 'cuil', 'doc',
        ],
        'school_name' => [
            'escuela', 'establecimiento', 'institución', 'institucion', 'nombre escuela', 'escuela nombre',
            'escuela_nombre', 'establecimiento educativo',
        ],
        'grade_or_year' => [
            'grado', 'año', 'sala', 'nivel', 'grado año', 'grado_año', 'sección', 'seccion',
            'división', 'division', 'curso',
        ],
    ];

    /**
     * Parsea el archivo y retorna un iterador de filas ya mapeadas a campos internos.
     *
     * @param UploadedFile $file
     * @param string $source  'civil_registry' | 'education'
     * @return \Generator<int, array>  índice de línea (base 1) => datos mapeados
     * @throws \RuntimeException si el archivo no puede leerse o no tiene cabeceras reconocidas
     */
    public function parse(UploadedFile $file, string $source): \Generator
    {
        $columnMap = $source === 'civil_registry'
            ? self::CIVIL_REGISTRY_COLUMNS
            : self::EDUCATION_COLUMNS;

        $extension = strtolower($file->getClientOriginalExtension());

        return match ($extension) {
            'csv'         => $this->parseCsv($file->getRealPath(), $columnMap),
            'xlsx', 'xls' => $this->parseExcel($file->getRealPath(), $columnMap),
            default       => throw new \RuntimeException("Formato de archivo no soportado: {$extension}. Use CSV o XLSX."),
        };
    }

    // ─── Parsers internos ─────────────────────────────────────────────────────────

    private function parseCsv(string $path, array $columnMap): \Generator
    {
        $delimiter = $this->detectDelimiter($path);

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("No se pudo abrir el archivo.");
        }

        // Descartar BOM UTF-8 si existe
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $headers = fgetcsv($handle, 0, $delimiter);
        if (! $headers) {
            fclose($handle);
            throw new \RuntimeException("El archivo CSV está vacío o no tiene cabeceras.");
        }

        $fieldMap = $this->buildFieldMap($headers, $columnMap);
        $line = 1;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $line++;
            if ($this->isBlankRow($row)) {
                continue;
            }
            // Alinear columnas: si la fila tiene más columnas que la cabecera, truncar
            $row = array_slice($row, 0, count($headers));
            $assoc = count($row) === count($headers)
                ? array_combine($headers, $row)
                : [];
            yield $line => $this->mapRow($assoc, $fieldMap);
        }

        fclose($handle);
    }

    private function parseExcel(string $path, array $columnMap): \Generator
    {
        // Requiere maatwebsite/excel instalado (composer require maatwebsite/excel)
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);

        if (empty($rows)) {
            throw new \RuntimeException("El archivo Excel está vacío.");
        }

        $headers = array_shift($rows); // primera fila = cabeceras
        $fieldMap = $this->buildFieldMap($headers, $columnMap);
        $line = 1;

        foreach ($rows as $row) {
            $line++;
            if ($this->isBlankRow($row)) {
                continue;
            }
            $assoc = array_combine($headers, array_slice($row, 0, count($headers))) ?: [];
            yield $line => $this->mapRow($assoc, $fieldMap);
        }
    }

    // ─── Utilidades ───────────────────────────────────────────────────────────────

    /**
     * Construye el mapa cabecera_real → campo_interno usando las variantes del columnMap.
     * Ej: "DNI Madre" → "mother_dni"
     */
    private function buildFieldMap(array $headers, array $columnMap): array
    {
        $fieldMap = [];
        foreach ($headers as $rawHeader) {
            $normalized = $this->normalizeHeader($rawHeader);
            foreach ($columnMap as $field => $variants) {
                foreach ($variants as $variant) {
                    if ($normalized === $this->normalizeHeader($variant)) {
                        $fieldMap[$rawHeader] = $field;
                        break 2;
                    }
                }
            }
        }
        return $fieldMap;
    }

    private function mapRow(array $assocRow, array $fieldMap): array
    {
        $mapped = [];
        foreach ($assocRow as $header => $value) {
            $field = $fieldMap[$header] ?? null;
            if ($field !== null) {
                $mapped[$field] = $this->sanitizeValue($field, $value);
            }
        }
        // Guardar también el raw original para raw_data
        $mapped['_raw'] = $assocRow;
        return $mapped;
    }

    private function sanitizeValue(string $field, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim((string) $value);

        if ($field === 'birth_date') {
            return $this->parseDate($value);
        }

        if (in_array($field, ['mother_dni', 'father_dni', 'dni'])) {
            // Eliminar puntos y espacios del DNI para normalización
            return preg_replace('/[\s.]/', '', $value);
        }

        return $value;
    }

    private function parseDate(string $value): ?string
    {
        $formats = ['d/m/Y', 'Y-m-d', 'd-m-Y', 'd/m/y', 'Y/m/d'];
        foreach ($formats as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);
                if ($date && $date->format($format) === $value) {
                    return $date->format('Y-m-d');
                }
            } catch (\Exception) {
                continue;
            }
        }
        return null; // fecha inválida — será marcado como error en el job
    }

    private function normalizeHeader(string $header): string
    {
        // Minúsculas, sin tildes, sin guiones/guiones_bajos, sin espacios extra
        $lower = mb_strtolower(trim($header));
        $decomposed = \Normalizer::normalize($lower, \Normalizer::FORM_D);
        $noAccents = preg_replace('/\p{Mn}/u', '', $decomposed);
        return preg_replace('/[\s_\-]+/', ' ', $noAccents);
    }

    private function isBlankRow(array $row): bool
    {
        return empty(array_filter($row, fn($v) => $v !== null && $v !== ''));
    }

    private function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return ',';
        }
        $firstLine = fgets($handle);
        fclose($handle);

        if ($firstLine === false) {
            return ',';
        }

        $commas     = substr_count($firstLine, ',');
        $semicolons = substr_count($firstLine, ';');
        return $semicolons > $commas ? ';' : ',';
    }
}
