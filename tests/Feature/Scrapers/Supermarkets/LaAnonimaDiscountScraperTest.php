<?php

namespace Tests\Feature\Scrapers\Supermarkets;

use App\Models\Wallet;
use App\Scrapers\Supermarkets\LaAnonimaDiscountScraper;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LaAnonimaDiscountScraperTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_parses_every_promo_card_into_one_dto_per_bank(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'www.laanonima.com.ar/*' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/la_anonima/promociones.html')),
            ),
        ]);

        $scraper = app(LaAnonimaDiscountScraper::class);
        $promotions = iterator_to_array($scraper->scrape());

        $this->assertSame('La Anónima', $scraper->merchantName());

        // The first card names 2 banks -> 2 DTOs; the "Tarjeta única"
        // card's only bank is a generic catch-all (see
        // config/bank_wallet_aliases.php's `skip` list) -> dropped.
        $this->assertCount(3, $promotions);

        $galicia = $promotions[0];
        $this->assertSame('banco-galicia', $galicia->walletSlug);
        $this->assertSame(25.0, $galicia->discountPercentage);
        $this->assertNull($galicia->installments);
        $this->assertSame(['Martes'], $galicia->validDays);
        $this->assertSame('Todos los martes, 25% de descuento pagando con tarjeta de débito.', $galicia->terms);
        $this->assertSame('https://www.laanonima.com.ar/empresa/promociones-y-descuentos#banco', $galicia->url);

        $hipotecario = $promotions[1];
        $this->assertSame('banco-hipotecario', $hipotecario->walletSlug);
        $this->assertSame($galicia->title, $hipotecario->title);
        $this->assertNotSame($galicia->externalId, $hipotecario->externalId);

        $sol = $promotions[2];
        $this->assertSame('banco-del-sol', $sol->walletSlug);
        $this->assertNull($sol->discountPercentage);
        $this->assertSame(12, $sol->installments);
        $this->assertSame(['Todos los días'], $sol->validDays);

        $this->assertSame(3, Wallet::count());
        $this->assertTrue(Wallet::where('name', 'Banco Hipotecario')->exists());
        $this->assertTrue(Wallet::where('name', 'Banco Del Sol')->exists());
        $this->assertFalse(Wallet::where('normalized_name', 'tarjetaunica')->exists());
    }

    public function test_returns_no_promotions_when_the_page_has_no_bank_tiles(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'www.laanonima.com.ar/*' => Http::response('<html><body></body></html>'),
        ]);

        $promotions = iterator_to_array(app(LaAnonimaDiscountScraper::class)->scrape());

        $this->assertCount(0, $promotions);
    }
}
