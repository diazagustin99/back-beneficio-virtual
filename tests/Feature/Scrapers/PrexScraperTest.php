<?php

namespace Tests\Feature\Scrapers;

use App\Scrapers\Prex\PrexScraper;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PrexScraperTest extends TestCase
{
    public function test_parses_every_promotion_from_the_embedded_next_data(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'www.prexcard.com.ar/promociones' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/prex/promociones.html')),
            ),
            'www.prexcard.com.ar/promociones/sky' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/prex/sky.html')),
            ),
            'www.prexcard.com.ar/promociones/smiles-millas' => Http::response(null, 404),
        ]);

        $scraper = new PrexScraper;
        $promotions = iterator_to_array($scraper->scrape());

        $this->assertSame('prex', $scraper->walletSlug());
        // The third listing entry (is_promotion: 0) is a banner, not a promo.
        $this->assertCount(2, $promotions);

        $discount = $promotions[0];
        $this->assertSame('Sky', $discount->merchantName);
        $this->assertSame('30% off en SKY para vuelos a Chile', $discount->title);
        $this->assertSame('https://d28a49jheumby6.cloudfront.net/web/landings/sky/imagen_presentacion/portada.png', $discount->merchantIconUrl);
        $this->assertNull($discount->category);
        $this->assertSame('Del 3 al 30 de agosto', $discount->description);
        $this->assertSame(30.0, $discount->discountPercentage);
        $this->assertNull($discount->cashbackPercentage);
        $this->assertNotNull($discount->startDate);
        $this->assertNotNull($discount->endDate);
        $this->assertSame('https://www.prexcard.com.ar/promociones/sky', $discount->url);
        $this->assertSame('157', $discount->externalId);
        $this->assertStringContainsString('PREX CARD S.A.S.', $discount->terms);
        $this->assertStringContainsString('Términos y Condiciones', $discount->terms);

        $cashback = $promotions[1];
        $this->assertSame('Smiles Millas', $cashback->merchantName);
        $this->assertNull($cashback->discountPercentage);
        $this->assertSame(20.0, $cashback->cashbackPercentage);
        $this->assertNull($cashback->merchantIconUrl);
        // The detail fetch for this one 404s — enrichment is best-effort.
        $this->assertNull($cashback->terms);
    }

    public function test_returns_no_promotions_when_next_data_is_missing(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'www.prexcard.com.ar/promociones' => Http::response('<html><body>challenge page</body></html>'),
        ]);

        $promotions = iterator_to_array((new PrexScraper)->scrape());

        $this->assertCount(0, $promotions);
    }
}
