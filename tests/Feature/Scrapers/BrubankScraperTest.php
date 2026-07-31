<?php

namespace Tests\Feature\Scrapers;

use App\Scrapers\Brubank\BrubankScraper;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BrubankScraperTest extends TestCase
{
    public function test_parses_every_plan_tier_card_from_the_static_html(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'www.brubank.com/beneficios' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/brubank/beneficios.html')),
            ),
            'help.brubank.com/es/articles/1-freddo-demo' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/brubank/article_freddo_demo.html')),
            ),
            'help.brubank.com/es/articles/2-tienda-demo' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/brubank/article_tienda_demo.html')),
            ),
            'help.brubank.com/es/articles/3-electro-demo' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/brubank/article_electro_demo.html')),
            ),
            'help.brubank.com/es/articles/4-resto-demo' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/brubank/article_resto_demo.html')),
            ),
        ]);

        $scraper = new BrubankScraper;
        $promotions = iterator_to_array($scraper->scrape());

        $this->assertSame('brubank', $scraper->walletSlug());
        $this->assertCount(4, $promotions);

        $cashback = $promotions[0];
        $this->assertSame('Freddo Demo', $cashback->merchantName);
        $this->assertSame('40% de reintegro', $cashback->title);
        $this->assertSame('https://cdn.example.com/freddo-demo.png', $cashback->merchantIconUrl);
        $this->assertNull($cashback->category);
        $this->assertNull($cashback->discountPercentage);
        $this->assertSame(40.0, $cashback->cashbackPercentage);
        $this->assertSame(8000.0, $cashback->reimbursementCap);
        $this->assertSame(['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'], $cashback->validDays);
        $this->assertStringContainsString('Plan Ultra', $cashback->description);
        $this->assertSame('Texto legal de Freddo Demo.', trim(explode("\n", $cashback->terms)[1] ?? $cashback->terms));
        $this->assertNotNull($cashback->startDate);
        $this->assertSame('2026-07-01', $cashback->startDate->format('Y-m-d'));
        $this->assertNotNull($cashback->endDate);
        $this->assertSame('2026-07-31', $cashback->endDate->format('Y-m-d'));
        $this->assertSame('es/articles/1-freddo-demo', $cashback->externalId);

        $discount = $promotions[1];
        $this->assertSame('Tienda Demo', $discount->merchantName);
        $this->assertSame(20.0, $discount->discountPercentage);
        $this->assertNull($discount->cashbackPercentage);
        $this->assertNull($discount->reimbursementCap);
        $this->assertSame(['Viernes', 'Sábado', 'Domingo'], $discount->validDays);
        $this->assertSame('Texto legal de Tienda Demo.', $discount->terms);
        $this->assertNull($discount->startDate);
        $this->assertNull($discount->endDate);

        $installments = $promotions[2];
        $this->assertSame('Electro Demo', $installments->merchantName);
        $this->assertSame('Hasta 6 cuotas sin interés', $installments->title);
        $this->assertNull($installments->discountPercentage);
        $this->assertNull($installments->cashbackPercentage);
        $this->assertSame(6, $installments->installments);
        $this->assertNotNull($installments->terms);
        // Prose date ranges are deliberately not parsed (see BrubankScraper::extractDateRange).
        $this->assertNull($installments->startDate);
        $this->assertNull($installments->endDate);

        $dayRange = $promotions[3];
        $this->assertSame('Resto Demo', $dayRange->merchantName);
        $this->assertSame(10.0, $dayRange->cashbackPercentage);
        $this->assertSame(3000.0, $dayRange->reimbursementCap);
        $this->assertSame(['Jueves', 'Viernes', 'Sábado', 'Domingo'], $dayRange->validDays);
        // The article page has no embedded data at all — must degrade gracefully.
        $this->assertNull($dayRange->terms);
        $this->assertNull($dayRange->startDate);
    }

    public function test_returns_no_promotions_when_the_page_has_no_cards(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'www.brubank.com/beneficios' => Http::response('<html><body></body></html>'),
        ]);

        $promotions = iterator_to_array((new BrubankScraper)->scrape());

        $this->assertCount(0, $promotions);
    }
}
