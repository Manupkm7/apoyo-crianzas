<?php

namespace App\Console\Commands;

use App\Models\Child;
use App\Services\Import\ImportMatchingService;
use Illuminate\Console\Command;

/**
 * Completa name_normalized/name_no_accents para los Child ya existentes
 * (los nuevos se calculan solos vía el hook 'saving' del modelo).
 *
 * Necesario una sola vez después de la migración que agrega esas columnas —
 * ImportMatchingService::matchChild() las usa para buscar candidatos por
 * nombre cuando el DNI de la fila importada no está disponible o no coincide.
 */
class BackfillChildrenNormalizedNames extends Command
{
    protected $signature = 'children:backfill-normalized-names';

    protected $description = 'Completa name_normalized/name_no_accents de los niños ya existentes';

    public function handle(): int
    {
        $total = Child::withTrashed()->whereNull('name_normalized')->count();

        if ($total === 0) {
            $this->info('No hay niños pendientes de backfill.');
            return self::SUCCESS;
        }

        $this->info("Actualizando {$total} niños…");
        $bar = $this->output->createProgressBar($total);

        // Update directo por id: el hook 'saving' del modelo solo recalcula cuando
        // first_name/last_name están "dirty", y acá no lo están (no cambian, solo
        // se completan las columnas nuevas) — por eso se calcula acá en vez de save().
        Child::withTrashed()
            ->whereNull('name_normalized')
            ->chunkById(200, function ($children) use ($bar) {
                foreach ($children as $child) {
                    Child::withTrashed()->where('id', $child->id)->update([
                        'name_normalized' => ImportMatchingService::normalizeName($child->first_name, $child->last_name),
                        'name_no_accents' => ImportMatchingService::normalizeNameNoAccents($child->first_name, $child->last_name),
                    ]);
                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine();
        $this->info('Listo.');

        return self::SUCCESS;
    }
}
