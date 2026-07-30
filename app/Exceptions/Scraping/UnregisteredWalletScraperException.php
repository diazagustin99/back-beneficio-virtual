<?php

namespace App\Exceptions\Scraping;

use App\Models\Wallet;
use RuntimeException;

class UnregisteredWalletScraperException extends RuntimeException
{
    public static function forWallet(Wallet $wallet): self
    {
        return new self("No scraper is registered for wallet [{$wallet->slug}] in config/scrapers.php.");
    }
}
