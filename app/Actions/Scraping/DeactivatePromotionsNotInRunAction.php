<?php

namespace App\Actions\Scraping;

use App\Models\Merchant;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class DeactivatePromotionsNotInRunAction
{
    /**
     * Mark every currently-active promotion of this wallet *sourced from
     * this same scraper* that wasn't seen in the current run as inactive.
     * Promotions are never deleted.
     *
     * Scoped by source (a promotion's `last_scrape_run`'s own scraper, a
     * `Wallet` or a `Merchant`), not just by `$wallet` itself, because one
     * wallet can now hold promotions from more than one scraper:
     * - MODO attributes a bank-exclusive promo directly to that bank's own
     *   wallet (see `ModoScraper`) — without source-scoping, MODO's run
     *   would see Macro's own natively-scraped promotions as "not in this
     *   run" and wrongly deactivate all of them.
     * - A supermarket scraper (see `MerchantScraperInterface`) attributes a
     *   discount to whatever bank it names — the same wallet a bank's own
     *   scraper already fills independently.
     *
     * @param  Wallet|Merchant  $source
     * @param  Collection<int, int>  $seenPromotionIds
     */
    public function handle(Wallet $wallet, Model $source, Collection $seenPromotionIds): int
    {
        return $wallet->promotions()
            ->where('is_active', true)
            ->whereHas('lastScrapeRun', fn ($query) => $query
                ->where('scrapeable_type', $source->getMorphClass())
                ->where('scrapeable_id', $source->getKey()))
            ->whereNotIn('id', $seenPromotionIds)
            ->update([
                'is_active' => false,
                'deactivated_at' => now(),
            ]);
    }
}
