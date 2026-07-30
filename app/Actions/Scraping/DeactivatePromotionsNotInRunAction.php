<?php

namespace App\Actions\Scraping;

use App\Models\Wallet;
use Illuminate\Support\Collection;

class DeactivatePromotionsNotInRunAction
{
    /**
     * Mark every currently-active promotion of this wallet that wasn't seen
     * in the current run as inactive. Promotions are never deleted.
     *
     * @param  Collection<int, int>  $seenPromotionIds
     */
    public function handle(Wallet $wallet, Collection $seenPromotionIds): int
    {
        return $wallet->promotions()
            ->where('is_active', true)
            ->whereNotIn('id', $seenPromotionIds)
            ->update([
                'is_active' => false,
                'deactivated_at' => now(),
            ]);
    }
}
