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
}
