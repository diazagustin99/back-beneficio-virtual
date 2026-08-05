<?php

namespace Tests\Feature\Scrapers;

use App\Scrapers\Galicia\GaliciaScraper;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GaliciaScraperTest extends TestCase
{
    public function test_parses_every_promotion_and_enriches_one_with_physical_locations(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'loyalty.bff.bancogalicia.com.ar/api/portal/personalizacion/v1/promociones/catalogo*' => function (Request $request) {
                $page = (int) ($request->data()['page'] ?? 1);

                if ($page === 1) {
                    return Http::response(
                        file_get_contents(base_path('tests/Fixtures/Scrapers/galicia/catalogo.json')),
                    );
                }

                // Empty second page stops pagination.
                return Http::response(['data' => ['list' => [], 'totalSize' => 2], 'errors' => null]);
            },
            'loyalty.bff.bancogalicia.com.ar/api/portal/catalogo/v1/promociones/idPromocion/146231' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/galicia/detail_anima_animal.json')),
            ),
            // Confirmed live behaviour: the detail service occasionally 404s
            // for an id the listing itself just returned — best-effort.
            'loyalty.bff.bancogalicia.com.ar/api/portal/catalogo/v1/promociones/idPromocion/159885' => Http::response(null, 404),
            'loyalty.bff.bancogalicia.com.ar/api/portal/catalogo/v1/locales/idPromocion/146231*' => function (Request $request) {
                $page = (int) ($request->data()['page'] ?? 1);

                if ($page === 1) {
                    return Http::response(
                        file_get_contents(base_path('tests/Fixtures/Scrapers/galicia/locales_anima_animal.json')),
                    );
                }

                return Http::response(['data' => ['list' => [], 'totalSize' => 1], 'errors' => null]);
            },
        ]);

        $scraper = new GaliciaScraper;
        $promotions = iterator_to_array($scraper->scrape());

        $this->assertSame('galicia', $scraper->walletSlug());
        $this->assertCount(2, $promotions);

        $enriched = $promotions[0];
        $this->assertSame('Anima Animal', $enriched->merchantName);
        $this->assertSame('15% de ahorro y hasta 3 cuotas sin interés', $enriched->title);
        $this->assertSame('Entretenimiento', $enriched->category);
        $this->assertNull($enriched->discountPercentage);
        $this->assertSame(15.0, $enriched->cashbackPercentage);
        $this->assertSame(3, $enriched->installments);
        $this->assertNull($enriched->reimbursementCap);
        $this->assertSame(['Todos los días'], $enriched->validDays);
        $this->assertNotNull($enriched->startDate);
        $this->assertSame('2026-01-09', $enriched->startDate->format('Y-m-d'));
        $this->assertNotNull($enriched->endDate);
        $this->assertSame('2026-09-01', $enriched->endDate->format('Y-m-d'));
        $this->assertStringContainsString('CARTERA DE CONSUMO', $enriched->terms);
        $this->assertSame('https://beneficios.galicia.ar/promocion/146231', $enriched->url);
        $this->assertSame('146231', $enriched->externalId);
        $this->assertSame(
            ['Tarjeta Access Now Visa', 'Tarjeta PowerCard Visa', 'Tarjeta Visa', 'Tarjeta Galicia Débito'],
            $enriched->paymentMethods,
        );
        $this->assertSame(
            'https://www.galicia.ar/content/dam/galicia/banco-galicia/personas/promociones/catalogo-de-beneficios/animaanimal.png',
            $enriched->merchantIconUrl,
        );
        $this->assertCount(1, $enriched->locations);
        $this->assertSame('store', $enriched->locations[0]->scope);
        $this->assertSame('CAPITAL FEDERAL', $enriched->locations[0]->province);
        $this->assertSame('RETIRO', $enriched->locations[0]->city);
        $this->assertSame('PRES MARCELO TORCUATO DE ALVEAR 1125', $enriched->locations[0]->address);
        $this->assertSame('Teatro Coliseo', $enriched->locations[0]->storeName);
        $this->assertSame(-34.59679, $enriched->locations[0]->latitude);
        $this->assertSame(-58.383102, $enriched->locations[0]->longitude);

        // Detail 404s for this one — the scraper keeps going with the
        // listing's own fields instead of aborting or dropping the promo.
        $fallback = $promotions[1];
        $this->assertSame('Vinoteca La Copa', $fallback->merchantName);
        $this->assertSame('20% de ahorro y hasta 6 cuotas sin interés', $fallback->title);
        $this->assertSame('Gastronomía', $fallback->category);
        // cashback/installments recovered by regex from the listing's own
        // pre-rendered `promocion` text since the detail call failed.
        $this->assertSame(20.0, $fallback->cashbackPercentage);
        $this->assertSame(6, $fallback->installments);
        $this->assertNull($fallback->terms);
        $this->assertNull($fallback->startDate);
        // Falls back to the listing's own ISO `fechaHasta`.
        $this->assertNotNull($fallback->endDate);
        $this->assertSame('2026-08-31', $fallback->endDate->format('Y-m-d'));
        $this->assertSame([], $fallback->validDays);
        $this->assertSame([], $fallback->locations);
        $this->assertSame(
            ['Tarjeta Access Now Visa', 'Tarjeta Mastercard', 'Tarjeta Visa', 'Tarjeta Galicia Débito'],
            $fallback->paymentMethods,
        );
    }

    public function test_returns_no_promotions_when_the_listing_has_none(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'loyalty.bff.bancogalicia.com.ar/api/portal/personalizacion/v1/promociones/catalogo*' => Http::response([
                'data' => ['list' => [], 'totalSize' => 0], 'errors' => null,
            ]),
        ]);

        $promotions = iterator_to_array((new GaliciaScraper)->scrape());

        $this->assertCount(0, $promotions);
    }
}
