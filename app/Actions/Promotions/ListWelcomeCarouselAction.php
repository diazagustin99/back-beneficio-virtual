<?php

namespace App\Actions\Promotions;

use App\Models\Promotion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ListWelcomeCarouselAction
{
    /**
     * One promotion per active wallet — the best-looking deal, ranked the
     * same way the frontend used to rank client-side (bigger % off beats
     * bigger cashback %, beats a fixed amount). Computed with a single
     * window-function query instead of the old "fetch every wallet's
     * promotions and pick the best in JS" N+1 that made the welcome screen
     * slow to load.
     *
     * @return Collection<int, Promotion>
     */
    public function handle(): Collection
    {
        $bestPromotionIds = DB::table(DB::raw('(
                SELECT
                    promotions.id,
                    ROW_NUMBER() OVER (
                        PARTITION BY promotions.wallet_id
                        ORDER BY
                            CASE
                                WHEN promotions.discount_percentage IS NOT NULL THEN 2000 + promotions.discount_percentage
                                WHEN promotions.cashback_percentage IS NOT NULL THEN 1000 + promotions.cashback_percentage
                                WHEN promotions.fixed_amount IS NOT NULL THEN promotions.fixed_amount / 1000
                                ELSE -1
                            END DESC
                    ) AS rn
                FROM promotions
                INNER JOIN wallets ON wallets.id = promotions.wallet_id
                WHERE promotions.is_active = 1 AND wallets.is_active = 1
            ) AS ranked_promotions'))
            ->where('rn', 1)
            ->pluck('id');

        return Promotion::query()
            ->whereIn('id', $bestPromotionIds)
            ->with(['wallet', 'merchant', 'category'])
            ->get();
    }
}
