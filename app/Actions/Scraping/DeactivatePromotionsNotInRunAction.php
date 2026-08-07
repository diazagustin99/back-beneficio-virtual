<?php

namespace App\Actions\Scraping;

use App\Models\Wallet;
use Illuminate\Support\Collection;

class DeactivatePromotionsNotInRunAction
{
    /**
     * Mark every currently-active promotion of this wallet *sourced from
     * this same scraper* that wasn't seen in the current run as inactive.
     * Promotions are never deleted.
     *
     * Scoped by source (a promotion's `last_scrape_run`'s own wallet), not
     * just by `$wallet` itself, because one wallet can now hold promotions
     * from more than one scraper — e.g. MODO attributes a bank-exclusive
     * promo directly to that bank's own wallet (see `ModoScraper`). Without
     * this, MODO's run would see Macro's own natively-scraped promotions as
     * "not in this run" and wrongly deactivate all of them.
     *
     * @param  Collection<int, int>  $seenPromotionIds
     */
    public function handle(Wallet $wallet, Wallet $sourceWallet, Collection $seenPromotionIds): int
    {
        return $wallet->promotions()
            ->where('is_active', true)
            ->whereHas('lastScrapeRun', fn ($query) => $query->where('wallet_id', $sourceWallet->id))
            ->whereNotIn('id', $seenPromotionIds)
            ->update([
                'is_active' => false,
                'deactivated_at' => now(),
            ]);
    }
}
