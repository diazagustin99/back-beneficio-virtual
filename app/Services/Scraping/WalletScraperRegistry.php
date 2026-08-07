<?php

namespace App\Services\Scraping;

use App\Contracts\Scrapers\WalletScraperInterface;
use App\Exceptions\Scraping\UnregisteredWalletScraperException;
use App\Models\Wallet;
use Illuminate\Contracts\Container\Container;

class WalletScraperRegistry
{
    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * Resolve the scraper registered for the given wallet.
     *
     * @throws UnregisteredWalletScraperException
     */
    public function for(Wallet $wallet): WalletScraperInterface
    {
        /** @var array<string, class-string<WalletScraperInterface>> $map */
        $map = config('scrapers.wallets', []);

        if (! isset($map[$wallet->slug])) {
            throw UnregisteredWalletScraperException::forWallet($wallet);
        }

        return $this->container->make($map[$wallet->slug]);
    }

    /**
     * Whether this wallet has its own scraper at all — false for an
     * attribution-only wallet (e.g. a bank that never has its own scraper,
     * only ever receiving promotions `ModoScraper` confirms are exclusive
     * to it). `DispatchDailyScrapesAction` uses this to never schedule a
     * scrape that's guaranteed to fail with `UnregisteredWalletScraperException`.
     */
    public function has(Wallet $wallet): bool
    {
        /** @var array<string, class-string<WalletScraperInterface>> $map */
        $map = config('scrapers.wallets', []);

        return isset($map[$wallet->slug]);
    }
}
