<?php

namespace App\Exceptions\Scraping;

use App\Models\Merchant;
use RuntimeException;

class UnregisteredMerchantScraperException extends RuntimeException
{
    public static function forMerchant(Merchant $merchant): self
    {
        return new self("No scraper is registered for merchant [{$merchant->slug}] in config/merchant_scrapers.php.");
    }
}
