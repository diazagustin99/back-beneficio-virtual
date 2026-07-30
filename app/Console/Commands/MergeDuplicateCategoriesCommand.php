<?php

namespace App\Console\Commands;

use App\Actions\PromotionCategories\MergeDuplicateCategoriesAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('categories:merge-duplicates')]
#[Description('Merge duplicate promotion categories (config/category_aliases.php) into their canonical category.')]
class MergeDuplicateCategoriesCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(MergeDuplicateCategoriesAction $action): int
    {
        $merges = $action->handle();

        if ($merges === []) {
            $this->info('No hay categorías duplicadas para fusionar.');

            return self::SUCCESS;
        }

        foreach ($merges as $merge) {
            $this->info("\"{$merge['variant']}\" -> \"{$merge['canonical']}\" ({$merge['promotions_moved']} promociones movidas)");
        }

        return self::SUCCESS;
    }
}
