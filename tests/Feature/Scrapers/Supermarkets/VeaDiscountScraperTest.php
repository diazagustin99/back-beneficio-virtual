<?php

namespace Tests\Feature\Scrapers\Supermarkets;

use App\Models\Wallet;
use App\Scrapers\Supermarkets\VeaDiscountScraper;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VeaDiscountScraperTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_parses_active_own_store_promotions_and_resolves_bank_wallets(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'www.vea.com.ar/api/dataentities/JN/documents/bankDiscount*' => Http::response(
                json_decode(file_get_contents(base_path('tests/Fixtures/Scrapers/cencosud/vea.json')), true),
            ),
        ]);

        $scraper = app(VeaDiscountScraper::class);
        $promotions = iterator_to_array($scraper->scrape());

        $this->assertSame('Vea', $scraper->merchantName());

        // 5 raw entries in the fixture: a percentage discount and a cuotas
        // discount for this store; a non-bank label ("Visa y Master"), an
        // expired entry, and an entry scoped to a different storefront are
        // all dropped.
        $this->assertCount(2, $promotions);

        $percentage = $promotions[0];
        $this->assertSame('banco-galicia', $percentage->walletSlug);
        $this->assertSame(20.0, $percentage->discountPercentage);
        $this->assertNull($percentage->installments);
        $this->assertSame(['Lunes', 'Jueves'], $percentage->validDays);
        $this->assertSame('20% de descuento con Banco Galicia', $percentage->title);

        $installments = $promotions[1];
        $this->assertSame('banco-nacion', $installments->walletSlug);
        $this->assertNull($installments->discountPercentage);
        $this->assertSame(12, $installments->installments);
        $this->assertSame(['Todos los días'], $installments->validDays);
        $this->assertSame('12 cuotas sin interés con Banco Nación', $installments->title);

        $this->assertSame(2, Wallet::count());
    }
}
