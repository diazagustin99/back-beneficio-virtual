<?php

namespace Tests\Support;

use App\Contracts\Scrapers\MerchantScraperInterface;
use App\DTOs\PromotionDTO;
use Throwable;

/**
 * Test-only double for MerchantScraperInterface — sibling of
 * FakeWalletScraper. Never referenced from config/merchant_scrapers.php.
 */
class FakeMerchantScraper implements MerchantScraperInterface
{
    /**
     * @param  iterable<int, PromotionDTO>  $promotions
     */
    public function __construct(
        private readonly string $merchantName,
        private readonly iterable $promotions = [],
        private readonly ?Throwable $throws = null,
    ) {}

    public function merchantName(): string
    {
        return $this->merchantName;
    }

    public function scrape(): iterable
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->promotions;
    }
}
