<?php

namespace Tests\Unit\Services\Scraping;

use App\Contracts\Scrapers\MerchantScraperInterface;
use App\DTOs\PromotionDTO;
use App\Exceptions\Scraping\UnregisteredMerchantScraperException;
use App\Models\Merchant;
use App\Services\Scraping\MerchantScraperRegistry;
use Tests\TestCase;

class MerchantScraperRegistryTest extends TestCase
{
    public function test_resolves_the_configured_scraper_for_a_mapped_slug(): void
    {
        config(['merchant_scrapers.merchants' => [
            'carrefour' => AlwaysEmptyMerchantScraperStub::class,
        ]]);

        $merchant = new Merchant(['slug' => 'carrefour']);

        $scraper = $this->app->make(MerchantScraperRegistry::class)->for($merchant);

        $this->assertInstanceOf(AlwaysEmptyMerchantScraperStub::class, $scraper);
    }

    public function test_throws_for_an_unmapped_slug(): void
    {
        config(['merchant_scrapers.merchants' => []]);

        $merchant = new Merchant(['slug' => 'carrefour']);

        $this->expectException(UnregisteredMerchantScraperException::class);

        $this->app->make(MerchantScraperRegistry::class)->for($merchant);
    }
}

class AlwaysEmptyMerchantScraperStub implements MerchantScraperInterface
{
    public function merchantName(): string
    {
        return 'Carrefour';
    }

    /**
     * @return iterable<int, PromotionDTO>
     */
    public function scrape(): iterable
    {
        return [];
    }
}
