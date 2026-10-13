<?php

namespace App\Console\Commands;

use App\Support\ImportedFarmPlot;
use Illuminate\Console\Command;

class BackfillImportedFarmPlotsCommand extends Command
{
    protected $signature = 'farmers:backfill-plots';

    protected $description = 'Create one unmapped farm plot for each farmer who has none';

    public function handle(): int
    {
        $inserted = ImportedFarmPlot::insertMissingForAll();
        $this->info("Created {$inserted} unmapped farm plot(s).");

        return self::SUCCESS;
    }
}
