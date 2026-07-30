<?php

namespace Tests\Feature\Scrapers;

use App\Scrapers\Modo\ModoScraper;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ModoScraperTest extends TestCase
{
    public function test_paginates_through_every_page_and_maps_fields(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*rewards/categories*' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/modo/categories.json')),
            ),
            '*rewards/slots*page=1*' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/modo/page_1.json')),
            ),
            '*rewards/slots*page=2*' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/modo/page_2.json')),
            ),
        ]);

        $scraper = new ModoScraper;
        $promotions = iterator_to_array($scraper->scrape());

        $this->assertSame('modo', $scraper->walletSlug());
        $this->assertCount(2, $promotions);

        $first = $promotions[0];
        $this->assertSame('Restó Demo', $first->merchantName);
        $this->assertSame('20% en Restó Demo', $first->title);
        $this->assertSame('https://assets.example.com/demo/resto-demo.jpg', $first->merchantIconUrl);
        $this->assertSame('Gastronomía', $first->category);
        $this->assertSame(20.0, $first->discountPercentage);
        $this->assertSame(['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'], $first->validDays);
        $this->assertSame(['Master', 'Visa'], $first->paymentMethods);
        $this->assertSame('promo-demo-0001', $first->externalId);
        $this->assertNotNull($first->startDate);
        $this->assertNotNull($first->endDate);

        $second = $promotions[1];
        $this->assertSame('Super Demo', $second->merchantName);
        $this->assertSame('Mercados', $second->category);
        $this->assertSame(['Martes', 'Jueves'], $second->validDays);
        $this->assertSame(['Visa Debit'], $second->paymentMethods);
    }

    public function test_returns_no_promotions_on_an_empty_catalog(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*rewards/categories*' => Http::response('[]'),
            '*rewards/slots*' => Http::response(json_encode([
                'data' => ['cards' => []],
                'metadata' => ['pagination' => ['page' => 1, 'page_results' => 0, 'total_pages' => 0, 'total_results' => 0]],
            ])),
        ]);

        $promotions = iterator_to_array((new ModoScraper)->scrape());

        $this->assertCount(0, $promotions);
    }
}
