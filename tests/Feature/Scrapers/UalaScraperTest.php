<?php

namespace Tests\Feature\Scrapers;

use App\Scrapers\Uala\UalaScraper;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UalaScraperTest extends TestCase
{
    public function test_parses_every_promotion_from_the_embedded_next_data(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'www.uala.com.ar/promociones' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/uala/promociones.html')),
            ),
        ]);

        $scraper = new UalaScraper;
        $promotions = iterator_to_array($scraper->scrape());

        $this->assertSame('uala', $scraper->walletSlug());
        $this->assertCount(2, $promotions);

        $discount = $promotions[0];
        $this->assertSame('Demo Rides', $discount->merchantName);
        $this->assertSame('100% de descuento', $discount->title);
        $this->assertSame('https://images.ctfassets.net/demo/logo-rides.webp', $discount->merchantIconUrl);
        $this->assertSame('Transporte', $discount->category);
        $this->assertSame(100.0, $discount->discountPercentage);
        $this->assertNull($discount->cashbackPercentage);
        $this->assertSame(15000.0, $discount->reimbursementCap);
        $this->assertSame(['Lunes', 'Martes'], $discount->validDays);
        $this->assertSame(['Tarjeta Prepaga', 'Tarjeta de Crédito'], $discount->paymentMethods);
        $this->assertSame('demorides100', $discount->externalId);
        $this->assertSame('https://demo-rides.example/ar', $discount->url);
        $this->assertNotNull($discount->endDate);
        $this->assertSame('Términos y condiciones de demo.', $discount->terms);

        $cashback = $promotions[1];
        $this->assertSame('Demo Gym', $cashback->merchantName);
        $this->assertNull($cashback->discountPercentage);
        $this->assertSame(20.0, $cashback->cashbackPercentage);
        // promotionLegal is inconsistent in production: sometimes a Contentful
        // rich-text document (covered by $discount above), sometimes a plain
        // string — both must resolve to a non-null `terms`.
        $this->assertSame('Términos y condiciones de demo en texto plano.', $cashback->terms);
    }

    public function test_returns_no_promotions_when_next_data_is_missing(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'www.uala.com.ar/promociones' => Http::response('<html><body>challenge page</body></html>'),
        ]);

        $promotions = iterator_to_array((new UalaScraper)->scrape());

        $this->assertCount(0, $promotions);
    }
}
