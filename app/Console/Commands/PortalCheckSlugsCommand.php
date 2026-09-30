<?php

namespace App\Console\Commands;

use App\Services\PortalSlugs;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `portal:check-slugs` — czy któryś slug łowiska nie zderzył się z adresem aplikacji (ADR-021, warstwa 4).
 *
 * Slug łowiska jest krótkim adresem wprost pod domeną. Przy nadawaniu i ręcznej zmianie kolizję
 * wyklucza `PortalSlugs::collidesWithApplication()`, ale nie widzi ona kierunku odwrotnego: NOWEJ
 * trasy dodanej po tym, jak łowisko wydrukowało swój adres na banerze. Ta komenda sprawdza slugi
 * z bazy wobec tras i listy zastrzeżonej BIEŻĄCEJ wersji aplikacji.
 *
 * ⚠️ **Niezatrzymująca** — zawsze kończy się kodem 0, a kolizję zgłasza ostrzeżeniem w logu
 * wdrożenia (`deploy.yml`, po migracjach). Naprawa to zwykle zmiana nazwy NASZEJ trasy, nie sluga:
 * slug jest już wydrukowany.
 */
class PortalCheckSlugsCommand extends Command
{
    protected $signature = 'portal:check-slugs';

    protected $description = 'Warn when a fishery slug (its short address) collides with an application route or a reserved name';

    public function handle(): int
    {
        $collisions = DB::table('fisheries')
            ->orderBy('id')
            ->get(['id', 'slug', 'deleted_at'])
            ->filter(fn (object $fishery): bool => PortalSlugs::collidesWithApplication((string) $fishery->slug));

        if ($collisions->isEmpty()) {
            $this->info('No fishery slug collides with the application.');

            return self::SUCCESS;
        }

        foreach ($collisions as $fishery) {
            $deleted = $fishery->deleted_at !== null ? ' (soft-deleted)' : '';
            $this->warn("Fishery #{$fishery->id} slug '{$fishery->slug}'{$deleted} collides with an application address — its short address does not reach the fishery.");
        }

        return self::SUCCESS;
    }
}
