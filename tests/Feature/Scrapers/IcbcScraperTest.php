<?php

namespace Tests\Feature\Scrapers;

use App\Scrapers\Icbc\IcbcScraper;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IcbcScraperTest extends TestCase
{
    public function test_parses_every_benefit_and_enriches_locations_from_the_detail_endpoint(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'prod-utilidades-icbc.pisol.net/api/web/v1/beneficios/get*' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/icbc/beneficios_get.json')),
            ),
            'prod-utilidades-icbc.pisol.net/api/web/v1/beneficios/detail*' => function (Request $request) {
                $id = $request->data()['id'] ?? null;

                if ($id === '13321') {
                    return Http::response(
                        file_get_contents(base_path('tests/Fixtures/Scrapers/icbc/detail_atalaya.json')),
                    );
                }

                return Http::response(['status' => 200, 'code' => 0, 'message' => '', 'data' => ['locations' => []]]);
            },
        ]);

        $scraper = new IcbcScraper;
        $promotions = iterator_to_array($scraper->scrape());

        $this->assertSame('icbc', $scraper->walletSlug());
        $this->assertCount(2, $promotions);

        $discount = $promotions[0];
        $this->assertSame('ADIDAS.COM', $discount->merchantName);
        $this->assertSame('25% de ahorro y hasta 9 cuotas sin interés', $discount->title);
        $this->assertSame('MODA', $discount->category);
        $this->assertSame(25.0, $discount->discountPercentage);
        $this->assertNull($discount->cashbackPercentage);
        $this->assertSame(9, $discount->installments);
        $this->assertSame(30000.0, $discount->reimbursementCap);
        $this->assertSame(['Martes'], $discount->validDays);
        $this->assertNotNull($discount->startDate);
        $this->assertNotNull($discount->endDate);
        $this->assertStringContainsString('DESCUENTO EN CHECKOUT', $discount->terms);
        $this->assertSame('https://www.adidas.com.ar/', $discount->url);
        $this->assertSame('13023', $discount->externalId);
        $this->assertSame(['Visa', 'Master'], $discount->paymentMethods);
        $this->assertSame('https://static1-beneficiosicbc.icbc-cdn.com.ar/images/yios70mw1X7o4y5A.jpeg', $discount->merchantIconUrl);
        $this->assertSame([], $discount->locations);

        $cashback = $promotions[1];
        $this->assertSame('ATALAYA', $cashback->merchantName);
        $this->assertNull($cashback->discountPercentage);
        $this->assertSame(30.0, $cashback->cashbackPercentage);
        $this->assertSame(8000.0, $cashback->reimbursementCap);
        $this->assertSame(['Todos los días'], $cashback->validDays);
        $this->assertSame(['Visa', 'Master', 'Debito', 'Modo'], $cashback->paymentMethods);
        // `web` is empty for this one — falls back to the ICBC site's own page.
        $this->assertSame(
            'https://www.beneficios.icbc.com.ar/promo/resto/atalaya-ahorro-credito-debito-icbc-todos-los-dias',
            $cashback->url,
        );
        // One location is missing street/city and is skipped; one is kept.
        $this->assertCount(1, $cashback->locations);
        $this->assertSame('store', $cashback->locations[0]->scope);
        $this->assertSame('Capital Federal', $cashback->locations[0]->province);
        $this->assertSame('CABA', $cashback->locations[0]->city);
        $this->assertSame('AV. CABILDO 2450', $cashback->locations[0]->address);
        $this->assertSame('ATALAYA', $cashback->locations[0]->storeName);
    }

    public function test_returns_no_promotions_when_the_listing_has_none(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'prod-utilidades-icbc.pisol.net/api/web/v1/beneficios/get*' => Http::response([
                'status' => 200, 'code' => 0, 'message' => '', 'data' => [],
            ]),
        ]);

        $promotions = iterator_to_array((new IcbcScraper)->scrape());

        $this->assertCount(0, $promotions);
    }
}
