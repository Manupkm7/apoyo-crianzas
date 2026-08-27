<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BirthRecord;
use App\Models\Child;
use App\Models\Department;
use App\Models\DeathRecord;
use App\Models\EducationObservation;
use App\Models\EducationRecord;
use App\Models\HealthRecord;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Institution;
use App\Models\Locality;
use App\Models\Province;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * DatabaseExportController — Exportación completa de los datos del sistema.
 *
 * Exclusivo del administrador (ni el coordinador, que solo tiene acceso de
 * lectura, puede usar esto). Genera un Excel (.xlsx, una hoja por tabla) para
 * que lo pueda abrir cualquier usuario final sin saber qué es un JSON, más
 * los PDF adjuntos de las observaciones educativas — todo dentro de un ZIP.
 *
 * Deliberadamente NO incluye:
 * - activity_log (historial de auditoría) — pedido explícito.
 * - personal_access_tokens — son credenciales de sesión activas, nunca deben exportarse.
 * - password / remember_token / intentos de login / IP del usuario — ya ocultos
 *   por $hidden en el modelo User; no se fuerza su visibilidad acá.
 *
 * Los campos cifrados (DNI, causa de defunción, etc.) SÍ se incluyen, pero
 * descifrados en texto plano — es la naturaleza de este formato de export.
 */
class DatabaseExportController extends Controller
{
    public function download(Request $request): BinaryFileResponse
    {
        abort_unless($request->user()->hasRole('admin'), 403, 'Solo el administrador puede exportar la base de datos.');

        $request->validate([
            'province_id'   => ['nullable', 'uuid', 'exists:provinces,id'],
            'department_id' => ['nullable', 'uuid', 'exists:departments,id'],
            'locality_id'   => ['nullable', 'uuid', 'exists:localities,id'],
        ]);

        $dir = storage_path('app/private/exports');
        File::ensureDirectoryExists($dir);

        $stamp   = now()->format('Y-m-d_His');
        $xlsxPath = $dir.DIRECTORY_SEPARATOR."datos_{$stamp}.xlsx";
        $zipPath  = $dir.DIRECTORY_SEPARATOR."export_{$stamp}.zip";

        // null = sin filtro (exporta todo, comportamiento por defecto). Array
        // (incluso vacío) = restringido a las instituciones de esa jurisdicción.
        $institutionIds = $this->resolveJurisdictionInstitutionIds($request);
        $childIds = $this->resolveChildIdsForInstitutions($institutionIds);

        (new Xlsx($this->buildSpreadsheet($institutionIds, $childIds)))->save($xlsxPath);

        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFile($xlsxPath, 'datos.xlsx');
        $this->addAttachments($zip, $institutionIds);
        $zip->addFromString('LEEME.txt', $this->manifest($request, $institutionIds));
        $zip->close();

        @unlink($xlsxPath);

        activity('export')
            ->causedBy($request->user())
            ->log('Descargó una exportación completa de la base de datos');

        return response()->download($zipPath, "export_{$stamp}.zip")->deleteFileAfterSend(true);
    }

    /**
     * Resuelve a qué instituciones restringir el export según la jurisdicción
     * elegida (provincia, departamento o localidad — la más específica manda).
     * null = sin filtro, exporta todo (comportamiento por defecto).
     */
    private function resolveJurisdictionInstitutionIds(Request $request): ?array
    {
        if (! $request->filled('locality_id') && ! $request->filled('department_id') && ! $request->filled('province_id')) {
            return null;
        }

        return Institution::query()
            ->when(
                $request->filled('locality_id'),
                fn ($q) => $q->where('locality_id', $request->query('locality_id')),
                fn ($q) => $q
                    ->when(
                        $request->filled('department_id'),
                        fn ($q2) => $q2->whereHas('locality', fn ($q3) => $q3->where('department_id', $request->query('department_id'))),
                        fn ($q2) => $q2->whereHas('locality.department', fn ($q3) => $q3->where('province_id', $request->query('province_id')))
                    )
            )
            ->pluck('id')
            ->all();
    }

    /**
     * Niños con algún registro (educativo, de salud, nacimiento o defunción)
     * en una de las instituciones filtradas. null = sin filtro de jurisdicción.
     */
    private function resolveChildIdsForInstitutions(?array $institutionIds): ?array
    {
        if ($institutionIds === null) {
            return null;
        }

        if ($institutionIds === []) {
            return [];
        }

        return Child::query()
            ->where(function ($q) use ($institutionIds) {
                $q->whereHas('educationRecord', fn ($q2) => $q2->whereIn('institution_id', $institutionIds))
                    ->orWhereHas('healthRecord', fn ($q2) => $q2->whereIn('institution_id', $institutionIds))
                    ->orWhereHas('birthRecord', fn ($q2) => $q2->whereIn('institution_id', $institutionIds))
                    ->orWhereHas('deathRecord', fn ($q2) => $q2->whereIn('institution_id', $institutionIds));
            })
            ->pluck('id')
            ->all();
    }

    /**
     * Arma el Excel completo: una hoja por tabla de datos de negocio, con
     * encabezados en español y relaciones ya resueltas a nombres legibles
     * (nunca UUIDs sueltos) para que lo pueda leer cualquier usuario final.
     *
     * $institutionIds / $childIds: null = sin filtro (todo el sistema).
     * Array (incluso vacío) = restringido a la jurisdicción elegida.
     */
    private function buildSpreadsheet(?array $institutionIds, ?array $childIds): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $index = 0;

        $institutions = Institution::withTrashed()
            ->with('locality.department.province')
            ->when($institutionIds !== null, fn ($q) => $q->whereIn('id', $institutionIds))
            ->orderBy('name')->get();
        $this->addSheet($spreadsheet, $index++, 'Instituciones', [
            'ID', 'Nombre', 'Tipo', 'Provincia', 'Departamento', 'Localidad', 'Dirección', 'Teléfono', 'Activa',
            'Ofrece jardín', 'Ofrece primario', 'Años primario', 'Ofrece secundario', 'Años secundario',
            'Creada', 'Actualizada', 'Eliminada',
        ], $institutions->map(fn (Institution $i) => [
            $i->id, $i->name, $this->institutionTypeLabel($i->type),
            $i->locality?->department?->province?->name, $i->locality?->department?->name, $i->locality?->name,
            $i->address, $i->phone, $this->bool($i->is_active),
            $this->bool($i->offers_jardin), $this->bool($i->offers_primario), $i->primario_years,
            $this->bool($i->offers_secundario), $i->secundario_years,
            $this->dt($i->created_at), $this->dt($i->updated_at), $this->dt($i->deleted_at),
        ])->all());

        $users = User::withTrashed()->with('institution')
            ->when($institutionIds !== null, fn ($q) => $q->whereIn('institution_id', $institutionIds))
            ->orderBy('name')->get();
        $this->addSheet($spreadsheet, $index++, 'Usuarios', [
            'ID', 'Nombre', 'Email', 'Institución', 'Roles', 'Activo', 'Responsable de institución',
            'Creado', 'Actualizado', 'Eliminado',
        ], $users->map(fn (User $u) => [
            $u->id, $u->name, $u->email, $u->institution?->name, $u->getRoleNames()->implode(', '),
            $this->bool($u->is_active), $this->bool($u->is_institution_head),
            $this->dt($u->created_at), $this->dt($u->updated_at), $this->dt($u->deleted_at),
        ])->all());

        $children = Child::withTrashed()
            ->when($childIds !== null, fn ($q) => $q->whereIn('id', $childIds))
            ->orderBy('last_name')->get();
        $this->addSheet($spreadsheet, $index++, 'Niños', [
            'ID', 'Nombre', 'Apellido', 'Fecha de nacimiento', 'Edad', 'DNI', 'Notas',
            'Creado', 'Actualizado', 'Eliminado',
        ], $children->map(fn (Child $c) => [
            $c->id, $c->first_name, $c->last_name, $this->d($c->birth_date), $c->age, $c->dni, $c->notes,
            $this->dt($c->created_at), $this->dt($c->updated_at), $this->dt($c->deleted_at),
        ])->all());

        $educationRecords = EducationRecord::withTrashed()->with(['institution', 'child'])
            ->when($institutionIds !== null, fn ($q) => $q->whereIn('institution_id', $institutionIds))
            ->get();
        $this->addSheet($spreadsheet, $index++, 'Reg. educativos', [
            'ID', 'Niño', 'Institución', 'Escuela', 'Nivel', 'Grado', 'Grado/año (texto libre)',
            'Inasistencias', 'Días asistidos', 'Días totales', 'Período', 'Escolarizado', 'Observaciones',
            'Creado', 'Actualizado', 'Eliminado',
        ], $educationRecords->map(fn (EducationRecord $r) => [
            $r->id, $this->childName($r->child), $r->institution?->name, $r->school_name,
            $r->level ? EducationRecord::levelLabel($r->level) : null,
            ($r->level && $r->grade) ? EducationRecord::gradeLabel($r->level, $r->grade) : $r->grade,
            $r->grade_or_year, $r->absences_count, $r->attendance_present_days, $r->attendance_total_days,
            $r->attendance_period_label, $this->bool($r->is_enrolled), $r->observations,
            $this->dt($r->created_at), $this->dt($r->updated_at), $this->dt($r->deleted_at),
        ])->all());

        $observations = EducationObservation::withTrashed()
            ->with(['author', 'educationRecord.child', 'educationRecord.institution'])
            ->when(
                $institutionIds !== null,
                fn ($q) => $q->whereHas('educationRecord', fn ($q2) => $q2->whereIn('institution_id', $institutionIds))
            )
            ->get();
        $this->addSheet($spreadsheet, $index++, 'Observaciones educ.', [
            'ID', 'Niño', 'Institución', 'Autor', 'Observación', 'Adjunto', 'Creado', 'Eliminado',
        ], $observations->map(fn (EducationObservation $o) => [
            $o->id, $this->childName($o->educationRecord?->child), $o->educationRecord?->institution?->name,
            $o->author?->name, $o->body, $o->attachment_original_name,
            $this->dt($o->created_at), $this->dt($o->deleted_at),
        ])->all());

        $healthRecords = HealthRecord::withTrashed()->with(['institution', 'child'])
            ->when($institutionIds !== null, fn ($q) => $q->whereIn('institution_id', $institutionIds))
            ->get();
        $this->addSheet($spreadsheet, $index++, 'Reg. de salud', [
            'ID', 'Niño', 'Institución', 'Centro de salud', 'Control niño sano al día', 'Vacunas al día',
            'Último control', 'Observaciones', 'Creado', 'Actualizado', 'Eliminado',
        ], $healthRecords->map(fn (HealthRecord $r) => [
            $r->id, $this->childName($r->child), $r->institution?->name, $r->health_center_name,
            $this->boolOrUnknown($r->healthy_checkup_current), $this->boolOrUnknown($r->vaccines_current),
            $this->d($r->last_checkup_date), $r->observations,
            $this->dt($r->created_at), $this->dt($r->updated_at), $this->dt($r->deleted_at),
        ])->all());

        $birthRecords = BirthRecord::withTrashed()->with(['institution', 'child'])
            ->when($institutionIds !== null, fn ($q) => $q->whereIn('institution_id', $institutionIds))
            ->get();
        $this->addSheet($spreadsheet, $index++, 'Reg. de nacimiento', [
            'ID', 'Nombre', 'Apellido', 'Niño vinculado', 'Fecha de nacimiento', 'Institución',
            'Nombre de la madre', 'DNI de la madre', 'Nombre del padre', 'DNI del padre',
            'Domicilio', 'Establecimiento de salud', 'Observaciones', 'Creado', 'Actualizado', 'Eliminado',
        ], $birthRecords->map(fn (BirthRecord $r) => [
            $r->id, $r->first_name, $r->last_name, $this->childName($r->child), $this->d($r->birth_date),
            $r->institution?->name, $r->mother_name, $r->mother_dni, $r->father_name, $r->father_dni,
            $r->address, $r->birth_establishment, $r->observations,
            $this->dt($r->created_at), $this->dt($r->updated_at), $this->dt($r->deleted_at),
        ])->all());

        $deathRecords = DeathRecord::withTrashed()->with(['institution', 'child'])
            ->when($institutionIds !== null, fn ($q) => $q->whereIn('institution_id', $institutionIds))
            ->get();
        $this->addSheet($spreadsheet, $index++, 'Reg. de defunción', [
            'ID', 'Nombre', 'Apellido', 'Niño vinculado', 'Fecha de nacimiento', 'Fecha de defunción',
            'Institución', 'DNI del niño', 'DNI de la madre', 'Causa de defunción', 'Observaciones',
            'Creado', 'Actualizado', 'Eliminado',
        ], $deathRecords->map(fn (DeathRecord $r) => [
            $r->id, $r->first_name, $r->last_name, $this->childName($r->child), $this->d($r->birth_date),
            $this->d($r->death_date), $r->institution?->name, $r->child_dni, $r->mother_dni,
            $r->cause_of_death, $r->observations,
            $this->dt($r->created_at), $this->dt($r->updated_at), $this->dt($r->deleted_at),
        ])->all());

        $importBatches = ImportBatch::with(['institution', 'uploader'])
            ->when($institutionIds !== null, fn ($q) => $q->whereIn('institution_id', $institutionIds))
            ->orderByDesc('created_at')->get();
        $this->addSheet($spreadsheet, $index++, 'Importaciones', [
            'ID', 'Origen', 'Institución', 'Estado', 'Archivo', 'Total filas', 'Procesadas',
            'Coincidencias', 'Parciales', 'Sin coincidencia', 'Errores', 'Subido por',
            'Iniciado', 'Finalizado', 'Mensaje de error',
        ], $importBatches->map(fn (ImportBatch $b) => [
            $b->id, $b->source, $b->institution?->name, $b->status, $b->original_filename,
            $b->total_rows, $b->processed_rows, $b->matched_rows, $b->partial_rows, $b->no_match_rows,
            $b->error_rows, $b->uploader?->name, $this->dt($b->started_at), $this->dt($b->finished_at),
            $b->error_message,
        ])->all());

        $importRows = ImportRow::with(['batch', 'child', 'resolver'])
            ->when(
                $institutionIds !== null,
                fn ($q) => $q->whereHas('batch', fn ($q2) => $q2->whereIn('institution_id', $institutionIds))
            )
            ->orderByDesc('created_at')->get();
        $this->addSheet($spreadsheet, $index++, 'Filas de importación', [
            'ID', 'Lote', 'Estado', 'Nombre normalizado', 'Fecha de nacimiento', 'Confianza de coincidencia',
            'Notas de coincidencia', 'Niño vinculado', 'Resuelto por', 'Resuelto', 'Mensaje de error', 'Línea',
        ], $importRows->map(fn (ImportRow $row) => [
            $row->id, $row->batch?->original_filename, $row->status, $row->name_normalized,
            $this->d($row->birth_date), $row->match_confidence, $row->match_notes,
            $this->childName($row->child), $row->resolver?->name, $this->dt($row->resolved_at),
            $row->error_message, $row->file_line_number,
        ])->all());

        $this->addSheet($spreadsheet, $index++, 'Roles', ['ID', 'Nombre'], Role::all()
            ->map(fn (Role $r) => [$r->id, $r->name])->all());

        $this->addSheet($spreadsheet, $index++, 'Permisos', ['ID', 'Nombre'], Permission::all()
            ->map(fn (Permission $p) => [$p->id, $p->name])->all());

        // El nombre de la columna de morph key está personalizado en config/permission.php
        // ('model_morph_key' => 'model_uuid'), no es el 'model_id' por defecto de Spatie.
        $roleAssignments = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->join('users', 'users.id', '=', 'model_has_roles.model_uuid')
            ->where('model_has_roles.model_type', User::class)
            ->when($institutionIds !== null, fn ($q) => $q->whereIn('users.institution_id', $institutionIds))
            ->select('users.email', 'roles.name as role')
            ->get();
        $this->addSheet($spreadsheet, $index++, 'Roles asignados', ['Usuario', 'Rol'], $roleAssignments
            ->map(fn ($row) => [$row->email, $row->role])->all());

        $rolePermissions = DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->select('roles.name as role', 'permissions.name as permission')
            ->get();
        $this->addSheet($spreadsheet, $index, 'Permisos por rol', ['Rol', 'Permiso'], $rolePermissions
            ->map(fn ($row) => [$row->role, $row->permission])->all());

        return $spreadsheet;
    }

    /**
     * Escribe una hoja con encabezados en negrita, ancho automático de columnas
     * y la primera fila congelada (para que sea cómoda de leer en Excel).
     */
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

    /**
     * Copia los PDF adjuntos de las observaciones educativas dentro del ZIP,
     * con su nombre original, para que sean legibles fuera del sistema.
     */
    private function addAttachments(ZipArchive $zip, ?array $institutionIds): void
    {
        $observations = EducationObservation::withTrashed()
            ->whereNotNull('attachment_path')
            ->when(
                $institutionIds !== null,
                fn ($q) => $q->whereHas('educationRecord', fn ($q2) => $q2->whereIn('institution_id', $institutionIds))
            )
            ->get();

        foreach ($observations as $observation) {
            if (! Storage::disk('local')->exists($observation->attachment_path)) {
                continue;
            }

            $name = $observation->attachment_original_name ?: basename($observation->attachment_path);

            $zip->addFile(
                Storage::disk('local')->path($observation->attachment_path),
                "attachments/education-observations/{$observation->id}/{$name}"
            );
        }
    }

    private function institutionTypeLabel(string $type): string
    {
        return match ($type) {
            'salud'             => 'Salud',
            'educacion'         => 'Educación',
            'desarrollo_social' => 'Desarrollo Social',
            'justicia'          => 'Justicia',
            'otro'              => 'Otro',
            default             => ucfirst($type),
        };
    }

    private function childName(?Child $child): ?string
    {
        return $child ? trim("{$child->first_name} {$child->last_name}") : null;
    }

    private function bool(?bool $value): string
    {
        return $value ? 'Sí' : 'No';
    }

    /**
     * A diferencia de bool(), distingue null ("sin dato" — ej: registro de salud
     * creado por importación sin esa columna) de false ("no", confirmado). Usarlo
     * solo en columnas nullable donde esa distinción es relevante.
     */
    private function boolOrUnknown(?bool $value): string
    {
        return match ($value) {
            true    => 'Sí',
            false   => 'No',
            default => 'Sin dato',
        };
    }

    private function dt(?Carbon $value): ?string
    {
        return $value?->format('d/m/Y H:i');
    }

    private function d(?Carbon $value): ?string
    {
        return $value?->format('d/m/Y');
    }

    private function jurisdictionLabel(Request $request): string
    {
        if ($request->filled('locality_id')) {
            return Locality::find($request->query('locality_id'))?->name ?? '—';
        }

        if ($request->filled('department_id')) {
            return Department::find($request->query('department_id'))?->name ?? '—';
        }

        return Province::find($request->query('province_id'))?->name ?? '—';
    }

    private function manifest(Request $request, ?array $institutionIds): string
    {
        return implode("\n", [
            $institutionIds === null
                ? 'Exportación completa — Sistema de Apoyo a la Crianza'
                : 'Exportación filtrada por jurisdicción — Sistema de Apoyo a la Crianza',
            'Generada: '.now()->toDateTimeString(),
            'Generada por: '.$request->user()->name.' ('.$request->user()->email.')',
            ...($institutionIds !== null ? [
                '',
                'Jurisdicción: '.$this->jurisdictionLabel($request),
                'Instituciones incluidas: '.count($institutionIds),
            ] : []),
            '',
            'datos.xlsx contiene una hoja por tabla: instituciones, usuarios, niños,',
            'registros educativos y de salud, observaciones educativas, registros de',
            'nacimiento y defunción, importaciones masivas, roles y permisos.',
            '',
            'attachments/ contiene los PDF adjuntos a las observaciones educativas.',
            '',
            'NO incluye (a propósito):',
            '- activity_log (historial de auditoría)',
            '- Tokens de sesión activos',
            '- Contraseñas ni datos de intentos de login de los usuarios',
            '',
            'Los DNI y demás datos cifrados en la base se incluyen DESCIFRADOS',
            'en datos.xlsx. Tratá este ZIP con el mismo cuidado que la base de',
            'datos original.',
        ]);
    }
}
