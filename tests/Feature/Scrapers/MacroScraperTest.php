<?php

namespace Tests\Feature\Scrapers;

use App\Scrapers\Macro\MacroScraper;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MacroScraperTest extends TestCase
{
    public function test_parses_and_enriches_a_promotion_from_the_detail_endpoint(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'apipublic.macro.com.ar/v1/card-benefits/provinces/*' => function (Request $request) {
                if (str_contains($request->url(), 'provinces/AR-C')) {
                    return Http::response([
                        'promotions' => [[
                            'name' => '47 STREET',
                            'sector' => 'Indumentaria',
                            'discount' => 30,
                            'segment' => 'Selecta',
                            'logo' => '47 street.png',
                            'city' => '47409TC2|47409',
                            'payment' => ['minimum' => 2, 'maximum' => 12, 'method' => 'TC'],
                            'days-week' => [
                                'monday' => false, 'tuesday' => false, 'wednesday' => false,
                                'thursday' => true, 'friday' => true, 'saturday' => true, 'sunday' => false,
                            ],
                        ]],
                        'pagination' => ['next-page' => false],
                    ]);
                }

                return Http::response(['promotions' => [], 'pagination' => ['next-page' => false]]);
            },
            'apipublic.macro.com.ar/v1/card-benefits/*' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/macro/detail_47_street.json')),
            ),
        ]);

        $scraper = new MacroScraper;
        $promotions = iterator_to_array($scraper->scrape());

        $this->assertSame('macro', $scraper->walletSlug());
        $this->assertCount(1, $promotions);

        $promo = $promotions[0];
        $this->assertSame('47 STREET', $promo->merchantName);
        $this->assertSame('30% de ahorro y hasta 12 cuotas sin interés', $promo->title);
        $this->assertSame('https://d15j2h49piim29.cloudfront.net/47 street.png', $promo->merchantIconUrl);
        $this->assertSame('Indumentaria', $promo->category);
        $this->assertSame('Selecta', $promo->description);
        $this->assertSame(30.0, $promo->discountPercentage);
        $this->assertSame(12, $promo->installments);
        $this->assertSame(['Jueves', 'Viernes', 'Sábados'], $promo->validDays);
        $this->assertNotNull($promo->startDate);
        $this->assertNotNull($promo->endDate);
        $this->assertStringContainsString('PROPUESTA PARA CARTERA', $promo->terms);
        $this->assertSame([
            'American Express Black Macro Selecta',
            'Mastercard Black Macro Selecta',
            'Visa Signature Macro Selecta',
        ], $promo->paymentMethods);
        $this->assertSame('47409TC2|47409', $promo->externalId);
        $this->assertCount(2, $promo->locations);
        $this->assertSame('store', $promo->locations[0]->scope);
        $this->assertSame('AR-B', $promo->locations[0]->province);
        $this->assertSame('ADROGUE', $promo->locations[0]->city);
    }

    public function test_dedupes_the_same_promotion_seen_across_provinces_and_follows_pagination_within_a_province(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'apipublic.macro.com.ar/v1/card-benefits/provinces/*' => function (Request $request) {
                $url = $request->url();
                $offset = (int) ($request->data()['offset'] ?? 1);

                if (str_contains($url, 'provinces/AR-C')) {
                    if ($offset === 1) {
                        return Http::response([
                            'promotions' => [$this->rawListingPromo('P2', 'Comercio Dos')],
                            'pagination' => ['next-page' => true],
                        ]);
                    }

                    return Http::response([
                        'promotions' => [$this->rawListingPromo('P3', 'Comercio Tres')],
                        'pagination' => ['next-page' => false],
                    ]);
                }

                if (str_contains($url, 'provinces/AR-B')) {
                    return Http::response([
                        'promotions' => [
                            $this->rawListingPromo('P2', 'Comercio Dos'),
                            $this->rawListingPromo('P4', 'Comercio Cuatro'),
                        ],
                        'pagination' => ['next-page' => false],
                    ]);
                }

                return Http::response(['promotions' => [], 'pagination' => ['next-page' => false]]);
            },
            'apipublic.macro.com.ar/v1/card-benefits/*' => Http::response(null, 404),
        ]);

        $promotions = iterator_to_array((new MacroScraper)->scrape());

        $this->assertCount(3, $promotions);
        $this->assertSame(
            ['Comercio Dos', 'Comercio Tres', 'Comercio Cuatro'],
            array_values(array_map(fn ($p) => $p->merchantName, $promotions)),
        );
    }

    public function test_falls_back_to_listing_only_fields_when_the_detail_endpoint_fails(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'apipublic.macro.com.ar/v1/card-benefits/provinces/*' => function (Request $request) {
                if (str_contains($request->url(), 'provinces/AR-C')) {
                    return Http::response([
                        'promotions' => [[
                            'name' => 'ADIDAS',
                            'sector' => 'Indumentaria',
                            'discount' => 20,
                            'logo' => 'adidas.png',
                            'segment' => 'General',
                            'city' => '99000TC0|99000',
                            'payment' => ['minimum' => 2, 'maximum' => 6, 'method' => 'TC'],
                            'days-week' => [
                                'monday' => true, 'tuesday' => true, 'wednesday' => true,
                                'thursday' => true, 'friday' => true, 'saturday' => true, 'sunday' => true,
                            ],
                        ]],
                        'pagination' => ['next-page' => false],
                    ]);
                }

                return Http::response(['promotions' => [], 'pagination' => ['next-page' => false]]);
            },
            'apipublic.macro.com.ar/v1/card-benefits/*' => Http::response(null, 500),
        ]);

        $promotions = iterator_to_array((new MacroScraper)->scrape());

        $this->assertCount(1, $promotions);

        $promo = $promotions[0];
        $this->assertSame('ADIDAS', $promo->merchantName);
        $this->assertSame('Indumentaria', $promo->category);
        $this->assertSame(20.0, $promo->discountPercentage);
        $this->assertSame(6, $promo->installments);
        $this->assertSame(['Todos los días'], $promo->validDays);
        $this->assertNull($promo->terms);
        $this->assertNull($promo->startDate);
        $this->assertNull($promo->endDate);
        $this->assertSame([], $promo->paymentMethods);
        $this->assertSame([], $promo->locations);
        $this->assertSame('https://d15j2h49piim29.cloudfront.net/adidas.png', $promo->merchantIconUrl);
        $this->assertSame('99000TC0|99000', $promo->externalId);
    }

    /**
     * @return array<string, mixed>
     */
    private function rawListingPromo(string $id, string $name): array
    {
        return [
            'name' => $name,
            'sector' => 'Otros',
            'discount' => 10,
            'logo' => '',
            'segment' => 'General',
            'city' => $id,
            'payment' => ['minimum' => 1, 'maximum' => 1, 'method' => 'TC'],
            'days-week' => [
                'monday' => true, 'tuesday' => true, 'wednesday' => true,
                'thursday' => true, 'friday' => true, 'saturday' => true, 'sunday' => true,
            ],
        ];
    }
}
