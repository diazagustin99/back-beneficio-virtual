<?php

namespace Tests\Feature\Scrapers\Supermarkets;

use App\Models\Wallet;
use App\Scrapers\Supermarkets\JumboDiscountScraper;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JumboDiscountScraperTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_parses_active_own_store_promotions_and_resolves_bank_wallets(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'www.jumbo.com.ar/api/dataentities/JN/documents/bankDiscount*' => Http::response(
                json_decode(file_get_contents(base_path('tests/Fixtures/Scrapers/cencosud/jumbo.json')), true),
            ),
        ]);

        $scraper = app(JumboDiscountScraper::class);
        $promotions = iterator_to_array($scraper->scrape());

        $this->assertSame('Jumbo', $scraper->merchantName());

        // Same shared feed shape as Vea's own fixture — see
        // VeaDiscountScraperTest for what's excluded and why.
        $this->assertCount(2, $promotions);

        $percentage = $promotions[0];
        $this->assertSame('banco-galicia', $percentage->walletSlug);
        $this->assertSame(20.0, $percentage->discountPercentage);
        $this->assertSame(['Lunes', 'Jueves'], $percentage->validDays);

        $installments = $promotions[1];
        $this->assertSame('banco-nacion', $installments->walletSlug);
        $this->assertSame(12, $installments->installments);

        $this->assertSame(2, Wallet::count());
    }
}
