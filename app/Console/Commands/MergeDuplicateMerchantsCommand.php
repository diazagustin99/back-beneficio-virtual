<?php

namespace App\Console\Commands;

use App\Actions\Merchants\MergeDuplicateMerchantsAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('merchants:merge-duplicates')]
#[Description('Merge merchants that only differ by case, accents, spacing, or punctuation into one.')]
class MergeDuplicateMerchantsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(MergeDuplicateMerchantsAction $action): int
    {
        $merges = $action->handle();

        if ($merges === []) {
            $this->info('No hay comercios duplicados para fusionar.');

            return self::SUCCESS;
        }

        $this->info(count($merges).' comercios fusionados:');

        foreach ($merges as $merge) {
            $this->line("  \"{$merge['variant']}\" -> \"{$merge['canonical']}\" ({$merge['promotions_moved']} promociones movidas)");
        }

        return self::SUCCESS;
    }
}
