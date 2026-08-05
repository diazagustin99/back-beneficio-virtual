<?php

namespace Tests\Feature\Scrapers;

use App\Scrapers\BancoCiudad\BancoCiudadScraper;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BancoCiudadScraperTest extends TestCase
{
    public function test_parses_every_promotion_enriching_with_detail_and_surviving_a_detail_failure(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            // Page 1 is the fixture's 3 promotions; every page after that
            // must come back empty or the scraper would keep walking pages
            // forever against a fake that doesn't shrink on its own.
            'www.bancociudad.com.ar/beneficios_rest/busqueda' => function (Request $request) {
                $page = $request->data()['data']['numero_pagina'] ?? null;

                if ($page !== 1) {
                    return Http::response(['mensaje' => 'OK', 'retorno' => ['beneficios' => [], 'cantidadTotalBeneficios' => 3, 'rubrosPorCliente' => []]]);
                }

                return Http::response(file_get_contents(base_path('tests/Fixtures/Scrapers/banco_ciudad/busqueda.json')));
            },
            'www.bancociudad.com.ar/beneficios_rest/13934' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/banco_ciudad/detalle_13934.json')),
            ),
            'www.bancociudad.com.ar/beneficios_rest/1621' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/banco_ciudad/detalle_1621.json')),
            ),
            // Confirmed live behaviour to guard against: a network failure
            // on the detail lookup must not drop the promo.
            'www.bancociudad.com.ar/beneficios_rest/15787' => Http::response(null, 500),
        ]);

        $scraper = new BancoCiudadScraper;
        $promotions = iterator_to_array($scraper->scrape());

        $this->assertSame('banco_ciudad', $scraper->walletSlug());
        $this->assertCount(3, $promotions);

        // RES: full enrichment — reintegro (cashback), real validity dates,
        // full legal text, category from the detail's own nombreRubro, and a
        // physical location survive.
        $res = $promotions[0];
        $this->assertSame('RES', $res->merchantName);
        $this->assertSame('30% de descuento', $res->title);
        $this->assertSame('https://www.bancociudad.com.ar/beneficios_rest/5432/logo', $res->merchantIconUrl);
        $this->assertSame('Comercios Vecinos', $res->category);
        $this->assertStringContainsString('reintegro', $res->description);
        $this->assertNull($res->discountPercentage);
        $this->assertSame(30.0, $res->cashbackPercentage);
        $this->assertNull($res->installments);
        $this->assertNull($res->reimbursementCap);
        $this->assertSame(['Todos los días'], $res->validDays);
        $this->assertNotNull($res->startDate);
        $this->assertSame('2025-12-01', $res->startDate->format('Y-m-d'));
        $this->assertNotNull($res->endDate);
        $this->assertSame('2026-12-31', $res->endDate->format('Y-m-d'));
        $this->assertStringContainsString('45 días', $res->terms);
        $this->assertStringNotContainsString('No aplica para tarjetas corporativas', $res->terms);
        $this->assertSame('https://www.bancociudad.com.ar/beneficios/detalle/13934', $res->url);
        $this->assertSame('13934', $res->externalId);
        $this->assertSame(['BUEPP'], $res->paymentMethods);
        $this->assertCount(1, $res->locations);
        $this->assertSame('store', $res->locations[0]->scope);
        $this->assertSame('Av. Escalada 4402, C1439 Cdad. Autónoma de Buenos Aires, Argentina', $res->locations[0]->address);
        $this->assertNull($res->locations[0]->city);
        $this->assertNull($res->locations[0]->province);
        $this->assertSame(-34.67646, $res->locations[0]->latitude);
        $this->assertSame(-58.454582, $res->locations[0]->longitude);

        // Ale Bikes: pure installments promo, no discount/cashback at all.
        $aleBikes = $promotions[1];
        $this->assertSame('Ale Bikes', $aleBikes->merchantName);
        $this->assertSame('24 cuotas sin interés', $aleBikes->title);
        $this->assertSame('Bicicleterías', $aleBikes->category);
        $this->assertNull($aleBikes->discountPercentage);
        $this->assertNull($aleBikes->cashbackPercentage);
        $this->assertSame(24, $aleBikes->installments);
        $this->assertSame(['Todos los días'], $aleBikes->validDays);
        $this->assertSame(['MASTERCARD', 'VISA'], $aleBikes->paymentMethods);
        $this->assertCount(1, $aleBikes->locations);
        $this->assertSame('1621', $aleBikes->externalId);

        // Combustible.: the detail lookup fails outright (HTTP 500) — the
        // promo must survive with the listing's own fields, category falls
        // back to the listing's rubroId resolved via rubrosPorCliente, and
        // dates/terms/locations simply stay empty rather than dropping the
        // promo or aborting the scrape.
        $combustible = $promotions[2];
        $this->assertSame('Combustible.', $combustible->merchantName);
        $this->assertSame('10% de descuento', $combustible->title);
        $this->assertSame('Combustible', $combustible->category);
        $this->assertNull($combustible->discountPercentage);
        $this->assertSame(10.0, $combustible->cashbackPercentage);
        $this->assertNull($combustible->installments);
        $this->assertSame(['Domingo'], $combustible->validDays);
        $this->assertSame(['VISA', 'MASTERCARD'], $combustible->paymentMethods);
        $this->assertNull($combustible->startDate);
        $this->assertNull($combustible->endDate);
        $this->assertNull($combustible->terms);
        $this->assertSame([], $combustible->locations);
        $this->assertSame('15787', $combustible->externalId);
    }

    public function test_returns_no_promotions_when_the_listing_has_none(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'www.bancociudad.com.ar/beneficios_rest/busqueda' => Http::response([
                'mensaje' => 'OK',
                'retorno' => ['beneficios' => [], 'cantidadTotalBeneficios' => 0, 'rubrosPorCliente' => []],
            ]),
        ]);

        $promotions = iterator_to_array((new BancoCiudadScraper)->scrape());

        $this->assertCount(0, $promotions);
    }

    public function test_walks_every_listing_page_using_the_sites_own_page_size(): void
    {
        Http::preventStrayRequests();

        // 14 promotions across a real 12-per-page catalog: page 1 full (12),
        // page 2 partial (2), page 3 empty — confirming the loop stops as
        // soon as a page comes back with nothing rather than looping
        // forever or under/over-shooting the real total.
        $page1 = array_map(
            fn (int $id) => ['id' => $id, 'comercio_nombre' => 'M'.$id, 'descuento' => 5, 'cuotas' => 0, 'rubroId' => 1, 'dias' => '-------', 'medios_pago' => [], 'promoDosPorUno' => 'N'],
            range(1, 12),
        );
        $page2 = array_map(
            fn (int $id) => ['id' => $id, 'comercio_nombre' => 'M'.$id, 'descuento' => 5, 'cuotas' => 0, 'rubroId' => 1, 'dias' => '-------', 'medios_pago' => [], 'promoDosPorUno' => 'N'],
            [13, 14],
        );

        Http::fake([
            'www.bancociudad.com.ar/beneficios_rest/busqueda' => function (Request $request) use ($page1, $page2) {
                $data = $request->data()['data'] ?? [];
                $size = $data['tamano_pagina'] ?? null;
                $page = $data['numero_pagina'] ?? null;

                // See the class docblock: any size other than the real
                // site's own (12) breaks the backend's pagination offset
                // math — asserted here so a regression back to a large
                // single request fails this test immediately.
                if ($size !== 12) {
                    return Http::response(['mensaje' => 'ERROR', 'retorno' => 'unexpected page size'], 500);
                }

                $beneficios = match ($page) {
                    1 => $page1,
                    2 => $page2,
                    default => [],
                };

                return Http::response([
                    'mensaje' => 'OK',
                    'retorno' => ['beneficios' => $beneficios, 'cantidadTotalBeneficios' => 14, 'rubrosPorCliente' => []],
                ]);
            },
            'www.bancociudad.com.ar/beneficios_rest/*' => Http::response(null, 500),
        ]);

        $promotions = iterator_to_array((new BancoCiudadScraper)->scrape());

        $this->assertCount(14, $promotions);
        $this->assertSame(
            array_map(fn (int $id) => 'M'.$id, range(1, 14)),
            array_map(fn ($p) => $p->merchantName, $promotions),
        );
    }
}
