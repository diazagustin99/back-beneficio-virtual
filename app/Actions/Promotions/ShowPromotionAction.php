<?php

namespace App\Actions\Promotions;

use App\Models\Promotion;

class ShowPromotionAction
{
    public function handle(Promotion $promotion): Promotion
    {
        return $promotion->load(['wallet', 'merchant', 'category', 'locations', 'paymentMethods']);
    }
}
