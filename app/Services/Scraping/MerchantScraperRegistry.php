<?php

namespace App\Services\Scraping;

use App\Contracts\Scrapers\MerchantScraperInterface;
use App\Exceptions\Scraping\UnregisteredMerchantScraperException;
use App\Models\Merchant;
use Illuminate\Contracts\Container\Container;

/**
 * Sibling of `WalletScraperRegistry` for the merchant-scraping pipeline —
 * kept as its own class rather than merged into one generic registry
 * because the two scraper contracts genuinely differ (`walletSlug()` vs
 * `merchantName()`), so a caller resolving "the" scraper would still have to
 * branch on the concrete type before calling it.
 */
class MerchantScraperRegistry
{
    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * Resolve the scraper registered for the given merchant.
     *
     * @throws UnregisteredMerchantScraperException
     */
    public function for(Merchant $merchant): MerchantScraperInterface
    {
        /** @var array<string, class-string<MerchantScraperInterface>> $map */
        $map = config('merchant_scrapers.merchants', []);

        if (! isset($map[$merchant->slug])) {
            throw UnregisteredMerchantScraperException::forMerchant($merchant);
        }

        return $this->container->make($map[$merchant->slug]);
    }
}
