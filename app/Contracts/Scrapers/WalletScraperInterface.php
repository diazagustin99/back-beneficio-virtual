<?php

namespace App\Contracts\Scrapers;

use App\DTOs\PromotionDTO;

/**
 * Plug-in boundary every wallet scraper implements. The persistence pipeline
 * only ever depends on this contract, never on a concrete wallet.
 */
interface WalletScraperInterface
{
    /**
     * The `wallets.slug` this scraper feeds. Must match a row in the database.
     */
    public function walletSlug(): string;

    /**
     * @return iterable<int, PromotionDTO>
     */
    public function scrape(): iterable;
}
