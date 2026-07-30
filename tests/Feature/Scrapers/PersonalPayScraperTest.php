<?php

namespace Tests\Feature\Scrapers;

use App\Scrapers\PersonalPay\PersonalPayScraper;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PersonalPayScraperTest extends TestCase
{
    public function test_parses_every_benefit_from_the_api(): void
    {
        // Fixture's meta.offset is 0 (not the realistic offset+count) so the
        // scraper's pagination loop stops after this single page — pagination
        // itself is covered separately below.
        Http::preventStrayRequests();
        Http::fake([
            'www.personal.com.ar/pay/api/benefits*' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/personal_pay/benefits_page1.json')),
            ),
        ]);

        $scraper = new PersonalPayScraper;
        $promotions = iterator_to_array($scraper->scrape());

        $this->assertSame('personal_pay', $scraper->walletSlug());
        $this->assertCount(2, $promotions);

        $cashback = $promotions[0];
        $this->assertSame('Farmacia Demo Oeste', $cashback->merchantName);
        $this->assertSame('20% de reintegro', $cashback->title);
        $this->assertSame('https://example.com/logo.png', $cashback->merchantIconUrl);
        $this->assertSame('Farmacia', $cashback->category);
        $this->assertNull($cashback->discountPercentage);
        $this->assertSame(20.0, $cashback->cashbackPercentage);
        $this->assertSame(3000.0, $cashback->reimbursementCap);
        $this->assertSame(14999.0, $cashback->minimumPurchase);
        $this->assertSame(['Miércoles'], $cashback->validDays);
        $this->assertSame(['Tarjeta Visa Personal Pay'], $cashback->paymentMethods);
        $this->assertSame('9350', $cashback->externalId);
        $this->assertNull($cashback->terms);
        $this->assertNotNull($cashback->endDate);

        $discount = $promotions[1];
        $this->assertSame('Restó Demo', $discount->merchantName);
        $this->assertSame(15.0, $discount->discountPercentage);
        $this->assertNull($discount->cashbackPercentage);
        $this->assertSame('Válido en sucursales adheridas.', $discount->terms);
    }

    public function test_follows_pagination_until_an_empty_page_is_returned(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'www.personal.com.ar/pay/api/benefits*' => function (Request $request) {
                $offset = $request->data()['offset'] ?? 0;

                if ($offset === 0) {
                    $benefits = array_map(
                        fn (int $i) => [
                            'id' => $i,
                            'title' => "Comercio {$i}",
                            'benefitValue' => '10% de descuento',
                            'discounts' => '10%',
                            'typeCode' => 'Descuento',
                            'paymentMethods' => [],
                            'levels' => [],
                            'days' => [],
                        ],
                        range(1, 100),
                    );

                    return Http::response(['data' => ['benefits' => $benefits, 'meta' => ['offset' => 100]]]);
                }

                if ($offset === 100) {
                    return Http::response(['data' => ['benefits' => [[
                        'id' => 101,
                        'title' => 'Último Comercio',
                        'benefitValue' => '10% de descuento',
                        'discounts' => '10%',
                        'typeCode' => 'Descuento',
                        'paymentMethods' => [],
                        'levels' => [],
                        'days' => [],
                    ]], 'meta' => ['offset' => 101]]]);
                }

                return Http::response(['data' => ['benefits' => [], 'meta' => ['offset' => $offset]]]);
            },
        ]);

        $promotions = iterator_to_array((new PersonalPayScraper)->scrape());

        $this->assertCount(101, $promotions);
        $this->assertSame('Último Comercio', $promotions[100]->merchantName);
    }

    public function test_returns_no_promotions_when_the_first_page_is_empty(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'www.personal.com.ar/pay/api/benefits*' => Http::response([
                'data' => ['benefits' => [], 'meta' => ['offset' => 0]],
            ]),
        ]);

        $promotions = iterator_to_array((new PersonalPayScraper)->scrape());

        $this->assertCount(0, $promotions);
    }
}
