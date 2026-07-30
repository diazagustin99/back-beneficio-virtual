<?php

namespace Tests\Feature\Scrapers;

use App\Scrapers\NaranjaX\NaranjaXScraper;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NaranjaXScraperTest extends TestCase
{
    public function test_parses_every_commerce_from_the_filter_endpoint(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'bkn-promotions.naranjax.com/*' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/naranja_x/filter_page1.json')),
            ),
        ]);

        $scraper = new NaranjaXScraper;
        $promotions = iterator_to_array($scraper->scrape());

        $this->assertSame('naranja_x', $scraper->walletSlug());
        $this->assertCount(2, $promotions);

        $first = $promotions[0];
        $this->assertSame('Tienda Demo Electro', $first->merchantName);
        $this->assertSame('Hasta 14 cuotas cero interés', $first->title);
        $this->assertSame('https://example.com/commerces/logos/demo-electro.png', $first->merchantIconUrl);
        $this->assertSame('Electro y tecnología', $first->category);
        $this->assertSame(14, $first->installments);
        $this->assertNull($first->discountPercentage);
        // Uses the plan marked CURRENT, not the UPCOMING one.
        $this->assertSame(['Sábado', 'Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes'], $first->validDays);
        $this->assertSame(['Crédito'], $first->paymentMethods);
        $this->assertSame('demo-0000000000000001', $first->externalId);
        $this->assertSame(
            'https://www.naranjax.com/promociones/ELECTRO_Y_TECNOLOGIA/ELECTRODOMESTICOS/tienda_demo_electro',
            $first->url,
        );
        $this->assertSame('2026-07-25', $first->startDate?->format('Y-m-d'));
        $this->assertSame('2026-07-31', $first->endDate?->format('Y-m-d'));
        $this->assertNull($first->terms);

        $second = $promotions[1];
        $this->assertSame('Resto Demo Norte', $second->merchantName);
        $this->assertSame('Gastronomía', $second->category);
        $this->assertSame(20.0, $second->discountPercentage);
        $this->assertSame(['Viernes', 'Sábado'], $second->validDays);
        $this->assertSame(['Débito'], $second->paymentMethods);
    }

    public function test_follows_pagination_until_a_short_page_is_returned(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'bkn-promotions.naranjax.com/*' => function (Request $request) {
                $page = $request->data()['pageOptions']['page'] ?? 1;

                if ($page === 1) {
                    $items = array_map(
                        fn (int $i) => [
                            'commerceName' => "Demo {$i}",
                            'title' => 'Promo demo',
                            'id' => "page1-{$i}",
                            'paymentMethods' => [],
                            'plans' => [],
                        ],
                        range(1, 50),
                    );

                    return Http::response([
                        'data' => $items,
                        'info' => ['page' => 1, 'itemsByPage' => 50, 'total' => 51, 'itemsInPage' => 50],
                    ]);
                }

                return Http::response([
                    'data' => [[
                        'commerceName' => 'Demo Last',
                        'title' => 'Promo demo final',
                        'id' => 'page2-1',
                        'paymentMethods' => [],
                        'plans' => [],
                    ]],
                    'info' => ['page' => 2, 'itemsByPage' => 50, 'total' => 51, 'itemsInPage' => 1],
                ]);
            },
        ]);

        $promotions = iterator_to_array((new NaranjaXScraper)->scrape());

        $this->assertCount(51, $promotions);
        $this->assertSame('Demo Last', $promotions[50]->merchantName);
    }

    public function test_stops_gracefully_instead_of_throwing_when_a_later_page_fails(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'bkn-promotions.naranjax.com/*' => function (Request $request) {
                $page = $request->data()['pageOptions']['page'] ?? 1;

                if ($page === 1) {
                    $items = array_map(
                        fn (int $i) => [
                            'commerceName' => "Demo {$i}",
                            'title' => 'Promo demo',
                            'id' => "page1-{$i}",
                            'paymentMethods' => [],
                            'plans' => [],
                        ],
                        range(1, 50),
                    );

                    return Http::response([
                        'data' => $items,
                        'info' => ['page' => 1, 'itemsByPage' => 50, 'total' => 100, 'itemsInPage' => 50],
                    ]);
                }

                // This wallet's real endpoint is flaky: a later page can 400
                // independent of the payload. The scraper must yield what it
                // already fetched instead of throwing and losing the run.
                return Http::response(['code' => 'UNKNOWN_ERROR'], 400);
            },
        ]);

        $promotions = iterator_to_array((new NaranjaXScraper)->scrape());

        $this->assertCount(50, $promotions);
    }

    public function test_returns_no_promotions_on_an_empty_page(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'bkn-promotions.naranjax.com/*' => Http::response([
                'data' => [],
                'info' => ['page' => 1, 'itemsByPage' => 100, 'total' => 0, 'itemsInPage' => 0],
            ]),
        ]);

        $promotions = iterator_to_array((new NaranjaXScraper)->scrape());

        $this->assertCount(0, $promotions);
    }
}
