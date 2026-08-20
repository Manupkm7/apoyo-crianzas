<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Locality;
use App\Models\Province;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * GeoSeeder — Carga el catálogo Provincia → Departamento → Localidad desde
 * database/seeders/data/data.json.
 *
 * Ese archivo es un volcado crudo de una consulta SQL Server (FOR JSON) de:
 *   SELECT p.id, p.nombre, p.codigo, d.nombre, d.codigo, d.provincia_id, l.nombre, l.codigo
 *   FROM provincias p
 *   LEFT JOIN departamentos d ON p.id = d.provincia_id
 *   LEFT JOIN localidades l ON l.departamento_id = d.id
 *
 * SQL Server no deduplica columnas con el mismo nombre al serializar: cada
 * fila del JSON tiene "nombre" y "codigo" repetidos tres veces (provincia,
 * departamento, localidad) en ESE orden fijo. Un json_decode() normal
 * colapsa claves duplicadas y se queda solo con la última — perdería
 * provincia y departamento por completo. Por eso NO se decodifica como JSON
 * genérico: se parsea posicionalmente con una regex que fija ese orden de
 * columnas, confirmado sobre el archivo real (8804 filas, sin nulls, mismo
 * shape en las 88043 líneas).
 *
 * Idempotente: upsert por external_code (el código tipo INDEC de cada fila,
 * no el id autoincremental de la base de origen), así se puede re-ejecutar
 * sin duplicar si el archivo se reemplaza por una versión más nueva.
 */
class GeoSeeder extends Seeder
{
    private const RECORD_PATTERN = '/
        \{\s*
            "id"\s*:\s*\d+\s*,\s*
            "nombre"\s*:\s*"(?<prov_nombre>(?:[^"\\\\]|\\\\.)*)"\s*,\s*
            "codigo"\s*:\s*"(?<prov_codigo>[^"]*)"\s*,\s*
            "nombre"\s*:\s*"(?<dep_nombre>(?:[^"\\\\]|\\\\.)*)"\s*,\s*
            "codigo"\s*:\s*"(?<dep_codigo>[^"]*)"\s*,\s*
            "provincia_id"\s*:\s*\d+\s*,\s*
            "nombre"\s*:\s*"(?<loc_nombre>(?:[^"\\\\]|\\\\.)*)"\s*,\s*
            "codigo"\s*:\s*"(?<loc_codigo>[^"]*)"\s*
        \}
    /xu';

    public function run(): void
    {
        $path = database_path('seeders/data/data.json');

        if (! File::exists($path)) {
            $this->command?->warn('GeoSeeder: no se encontró database/seeders/data/data.json — se omite.');

            return;
        }

        $records = $this->parseRecords(File::get($path));

        if ($records === []) {
            $this->command?->warn('GeoSeeder: data.json no tiene el formato esperado (columnas p.*, d.*, l.* en ese orden) — se omite.');

            return;
        }

        $provinces   = [];
        $departments = [];

        foreach ($records as $row) {
            $provinceCode = $row['prov_codigo'];
            $provinces[$provinceCode] ??= Province::updateOrCreate(
                ['external_code' => $provinceCode],
                ['name' => $this->unescape($row['prov_nombre'])],
            );
            $province = $provinces[$provinceCode];

            $departmentKey = $provinceCode.'|'.$row['dep_codigo'];
            $departments[$departmentKey] ??= Department::updateOrCreate(
                ['province_id' => $province->id, 'external_code' => $row['dep_codigo']],
                ['name' => $this->unescape($row['dep_nombre'])],
            );
            $department = $departments[$departmentKey];

            Locality::updateOrCreate(
                ['department_id' => $department->id, 'external_code' => $row['loc_codigo']],
                ['name' => $this->unescape($row['loc_nombre'])],
            );
        }

        $this->command?->info(sprintf(
            'GeoSeeder: %d provincias, %d departamentos, %d localidades.',
            count($provinces),
            count($departments),
            count($records),
        ));
    }

    private function parseRecords(string $content): array
    {
        preg_match_all(self::RECORD_PATTERN, $content, $matches, PREG_SET_ORDER);

        return $matches;
    }

    private function unescape(string $raw): string
    {
        return json_decode('"'.$raw.'"', flags: JSON_THROW_ON_ERROR);
    }
}
