<?php

namespace App\Console\Commands;

use App\Actions\Notifications\DispatchDailyMerchantDiscountNotificationsAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('notifications:daily-merchant-discounts')]
#[Description('Notify each opted-in user whose followed merchants have a discount valid today.')]
class NotifyDailyMerchantDiscountsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DispatchDailyMerchantDiscountNotificationsAction $dispatch): int
    {
        $dispatch->handle();

        $this->info('Daily merchant discount notifications dispatched.');

        return self::SUCCESS;
    }
}
