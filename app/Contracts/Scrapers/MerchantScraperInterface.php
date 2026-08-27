<?php

namespace App\Contracts\Scrapers;

use App\DTOs\PromotionDTO;

/**
 * Plug-in boundary every supermarket ("merchant") scraper implements —
 * sibling of `WalletScraperInterface`, for the pipeline that scrapes a
 * merchant's own page instead of a wallet's. Unlike a wallet scraper, every
 * DTO yielded here must already carry a real, resolvable `walletSlug` (see
 * `ResolveWalletFromBankNameAction`) — a merchant is never itself a valid
 * promotion wallet, so `SyncPromotionsFromScraperAction` has no fallback for
 * this pipeline the way it does for wallet scrapers.
 */
interface MerchantScraperInterface
{
    /**
     * The `merchants.name` this scraper feeds — resolved via
     * `ResolveMerchantAction`, same as any other scraper's `merchantName`.
     */
    public function merchantName(): string;

    /**
     * @return iterable<int, PromotionDTO>
     */
    public function scrape(): iterable;
}
