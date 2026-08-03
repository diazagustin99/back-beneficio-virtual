<?php

namespace App\Console\Commands;

use App\Actions\Promotions\DeactivateExpiredPromotionsAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('promotions:deactivate-expired')]
#[Description('Deactivate promotions whose ends_at date has already passed, so they stop being shown.')]
class DeactivateExpiredPromotionsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DeactivateExpiredPromotionsAction $action): int
    {
        $count = $action->handle();

        $this->info("{$count} promoción(es) vencida(s) desactivada(s).");

        return self::SUCCESS;
    }
}
