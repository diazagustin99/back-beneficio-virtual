<?php

namespace Tests\Feature\Scrapers;

use App\Scrapers\MercadoPago\MercadoPagoScraper;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MercadoPagoScraperTest extends TestCase
{
    public function test_parses_every_promotion_card_from_the_static_html(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'promociones.mercadopago.com.ar/*' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/mercado_pago/promociones.html')),
            ),
        ]);

        $scraper = new MercadoPagoScraper;
        $promotions = iterator_to_array($scraper->scrape());

        $this->assertSame('mercado_pago', $scraper->walletSlug());
        $this->assertCount(2, $promotions);

        $first = $promotions[0];
        $this->assertSame('TIENDA TEST', $first->merchantName);
        $this->assertSame('Hasta 30% OFF', $first->title);
        $this->assertSame('/logo-tienda-test.jpg', $first->merchantIconUrl);
        $this->assertSame(30.0, $first->discountPercentage);
        $this->assertSame(6, $first->installments);
        $this->assertNull($first->category);
        $this->assertSame('seller/tienda-test', $first->externalId);
        $this->assertSame('https://promociones.mercadopago.com.ar/seller/tienda-test/', $first->url);
        $this->assertNotNull($first->startDate);
        $this->assertNotNull($first->endDate);

        $second = $promotions[1];
        $this->assertSame('Farmacia Demo', $second->merchantName);
        $this->assertSame(15.0, $second->cashbackPercentage);
        $this->assertNull($second->discountPercentage);
    }

    public function test_returns_no_promotions_when_the_page_has_no_cards(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'promociones.mercadopago.com.ar/*' => Http::response('<html><body></body></html>'),
        ]);

        $promotions = iterator_to_array((new MercadoPagoScraper)->scrape());

        $this->assertCount(0, $promotions);
    }
}
