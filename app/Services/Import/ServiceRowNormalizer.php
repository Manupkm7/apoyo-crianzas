<?php

namespace App\Services\Import;

use App\Models\Institution;
use App\Models\ServiceType;
use App\Support\ServicePeriod;
use App\Support\Sector;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Prestaciones dentro del flujo de matcheo de niños: cualquier hoja (Registro
 * Civil, Educación o Salud) puede traer, en la misma fila que identifica al niño,
 * las columnas de una prestación (Fecha/período, Nro prestación, Sector, Efector,
 * Nombre prestación, Observaciones, Alerta). No hay una fuente aparte.
 *
 * Valida y completa esas columnas ANTES de guardar la fila como ImportRow: si algo
 * no cierra, la fila queda en 'error' con un mensaje claro para el operador, en
 * vez de descubrirlo recién al confirmar. Al confirmar la fila, además del
 * registro de dominio se carga la prestación (ImportController::confirmRow()).
 *
 * Agrega a la fila los valores ya resueltos (se guardan en raw_data y los usa
 * ImportController::createChildServiceFromRow() al confirmar):
 *   - period_type / period_number / period_year  ← columna "Fecha" (TRIM12026)
 *   - sector_key                                  ← columna "Sector"
 *   - institution_id / institution_name           ← columna "Efector" (SIEMPRE una institución del sistema)
 *   - service_type_id (si ya existe en el catálogo; si no, se crea al confirmar)
 *   - alert (bool, default false)
 */
class ServiceRowNormalizer
{
    /** @var array<string, Institution|null> caché nombre normalizado → institución, por archivo */
    private array $institutionCache = [];

    /**
     * ¿La fila informa una prestación? Alcanza con el nombre de la prestación o
     * el período: una hoja con columnas de prestación puede tener filas sin ella.
     */
    public static function hasService(array $rowData): bool
    {
        return trim((string) ($rowData['service_name'] ?? '')) !== ''
            || trim((string) ($rowData['period'] ?? '')) !== ''
            || ! empty($rowData['period_type']);
    }

    /**
     * @throws InvalidArgumentException con el mensaje para el operador
     */
    public function normalize(array $rowData): array
    {
        $period = ServicePeriod::parse($rowData['period'] ?? null);
        if ($period === null) {
            $raw = $rowData['period'] ?? '';
            throw new InvalidArgumentException(
                $raw === ''
                    ? 'Falta el período (columna "Fecha"). Formato esperado: TRIM12026 (trimestre 1 de 2026) o BIM32026 (bimestre 3 de 2026).'
                    : "El período \"{$raw}\" no se reconoce. Formato esperado: TRIM12026 (trimestre 1 de 2026) o BIM32026 (bimestre 3 de 2026)."
            );
        }

        $serviceName = trim((string) ($rowData['service_name'] ?? ''));
        if ($serviceName === '') {
            throw new InvalidArgumentException('Falta el nombre de la prestación (columna "Nombre prestación").');
        }

        $sectorText = $rowData['sector'] ?? null;
        $sector     = Sector::fromText($sectorText);
        $type       = ServiceType::findByName($serviceName);

        if ($sector === null) {
            if ($sectorText !== null && trim((string) $sectorText) !== '') {
                throw new InvalidArgumentException(
                    "El sector \"{$sectorText}\" no se reconoce. Valores válidos: " . implode(', ', Sector::LABELS) . '.'
                );
            }
            // Sin sector en el archivo: se toma el del catálogo si la prestación ya existe.
            $sector = $type?->sector ?? throw new InvalidArgumentException(
                'Falta el sector (columna "Sector") y la prestación todavía no está en el catálogo para tomarlo de ahí.'
            );
        }

        $providerName = trim((string) ($rowData['provider'] ?? ''));
        if ($providerName === '') {
            throw new InvalidArgumentException('Falta el efector (columna "Efector").');
        }

        $institution = $this->findInstitution($providerName);
        if ($institution === null) {
            throw new InvalidArgumentException(
                "El efector \"{$providerName}\" no está dado de alta como institución en el sistema (o está inactivo). Darlo de alta y volver a subir el archivo."
            );
        }

        if ($type !== null && ! $type->is_active) {
            throw new InvalidArgumentException(
                "La prestación \"{$serviceName}\" está desactivada en el catálogo. Reactivarla o corregir el nombre en el archivo."
            );
        }

        return [
            ...$rowData,
            'service_name'     => $serviceName,
            'service_type_id'  => $type?->id,
            'sector_key'       => $sector,
            'period_type'      => $period['type'],
            'period_number'    => $period['number'],
            'period_year'      => $period['year'],
            'institution_id'   => $institution->id,
            'institution_name' => $institution->name,
            'alert'            => (bool) ($rowData['alert'] ?? false),
        ];
    }

    /**
     * Busca la institución activa por nombre, sin importar mayúsculas, tildes ni
     * espacios repetidos ("Jardín  Ardillitas" = "jardin ardillitas"). Si hay más
     * de una con el mismo nombre normalizado, es ambiguo: se rechaza la fila.
     */
    private function findInstitution(string $name): ?Institution
    {
        $key = $this->normalizeName($name);

        if (array_key_exists($key, $this->institutionCache)) {
            return $this->institutionCache[$key];
        }

        $matches = Institution::query()
            ->where('is_active', true)
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($name))])
            ->get();

        // Sin coincidencia exacta (case-insensitive): comparar sin tildes en PHP.
        if ($matches->isEmpty()) {
            $matches = Institution::query()
                ->where('is_active', true)
                ->get(['id', 'name', 'type', 'is_active'])
                ->filter(fn (Institution $i) => $this->normalizeName($i->name) === $key)
                ->values();
        }

        if ($matches->count() > 1) {
            throw new InvalidArgumentException(
                "Hay {$matches->count()} instituciones llamadas \"{$name}\": no se puede saber cuál es el efector. Renombrar una de ellas para que sean distinguibles."
            );
        }

        return $this->institutionCache[$key] = $matches->first();
    }

    private function normalizeName(string $name): string
    {
        return Str::of($name)->ascii()->lower()->squish()->toString();
    }
}
