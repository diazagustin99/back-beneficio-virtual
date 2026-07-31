<?php

namespace App\Console\Commands;

use App\Actions\Promotions\MergeDuplicatePromotionsAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('promotions:merge-duplicates')]
#[Description('Merge promotions for the same wallet+merchant that offer the exact same thing over overlapping dates.')]
class MergeDuplicatePromotionsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(MergeDuplicatePromotionsAction $action): int
    {
        $merges = $action->handle();

        if ($merges === []) {
            $this->info('No hay promociones duplicadas para fusionar.');

            return self::SUCCESS;
        }

        $this->info(count($merges).' grupos de promociones fusionados:');

        foreach ($merges as $merge) {
            $mergedIds = implode(', ', $merge['merged_ids']);
            $this->line("  [{$merge['wallet']}] \"{$merge['merchant']}\": sobrevive #{$merge['survivor_id']}, se borran #{$mergedIds}");
        }

        return self::SUCCESS;
    }
}
