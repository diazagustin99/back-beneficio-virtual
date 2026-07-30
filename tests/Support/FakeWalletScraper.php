<?php

namespace Tests\Support;

use App\Contracts\Scrapers\WalletScraperInterface;
use App\DTOs\PromotionDTO;
use Throwable;

/**
 * Test-only double for WalletScraperInterface. Never referenced from
 * config/scrapers.php — it exists purely to exercise the sync pipeline
 * before any real wallet scraper is built.
 */
class FakeWalletScraper implements WalletScraperInterface
{
    /**
     * @param  iterable<int, PromotionDTO>  $promotions
     */
    public function __construct(
        private readonly string $slug,
        private readonly iterable $promotions = [],
        private readonly ?Throwable $throws = null,
    ) {}

    public function walletSlug(): string
    {
        return $this->slug;
    }

    public function scrape(): iterable
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->promotions;
    }
}
