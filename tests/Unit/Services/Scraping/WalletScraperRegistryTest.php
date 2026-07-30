<?php

namespace Tests\Unit\Services\Scraping;

use App\Contracts\Scrapers\WalletScraperInterface;
use App\DTOs\PromotionDTO;
use App\Exceptions\Scraping\UnregisteredWalletScraperException;
use App\Models\Wallet;
use App\Services\Scraping\WalletScraperRegistry;
use Tests\TestCase;

class WalletScraperRegistryTest extends TestCase
{
    public function test_resolves_the_configured_scraper_for_a_mapped_slug(): void
    {
        config(['scrapers.wallets' => [
            'mercado_pago' => AlwaysEmptyScraperStub::class,
        ]]);

        $wallet = new Wallet(['slug' => 'mercado_pago']);

        $scraper = $this->app->make(WalletScraperRegistry::class)->for($wallet);

        $this->assertInstanceOf(AlwaysEmptyScraperStub::class, $scraper);
    }

    public function test_throws_for_an_unmapped_slug(): void
    {
        config(['scrapers.wallets' => []]);

        $wallet = new Wallet(['slug' => 'mercado_pago']);

        $this->expectException(UnregisteredWalletScraperException::class);

        $this->app->make(WalletScraperRegistry::class)->for($wallet);
    }
}

class AlwaysEmptyScraperStub implements WalletScraperInterface
{
    public function walletSlug(): string
    {
        return 'mercado_pago';
    }

    /**
     * @return iterable<int, PromotionDTO>
     */
    public function scrape(): iterable
    {
        return [];
    }
}
