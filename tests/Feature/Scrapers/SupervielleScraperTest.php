<?php

namespace Tests\Feature\Scrapers;

use App\Scrapers\Supervielle\SupervielleScraper;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupervielleScraperTest extends TestCase
{
    public function test_parses_every_promotion_across_rubros_and_client_segments_deduplicating_mirrors_and_surviving_a_detail_failure(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'www.supervielle.com.ar/api/rubros?esIdentite=false' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/supervielle/rubros_clasico.json')),
            ),
            'www.supervielle.com.ar/api/rubros?esIdentite=true' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/supervielle/rubros_identite.json')),
            ),
            'www.supervielle.com.ar/api/beneficios?rubro=Automotor&esIdentite=false' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/supervielle/beneficios_automotor_clasico.json')),
            ),
            'www.supervielle.com.ar/api/beneficios?rubro=Turismo&esIdentite=false' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/supervielle/beneficios_turismo_clasico.json')),
            ),
            // Same two promotions as the "Clásico" Automotor listing above,
            // byte-for-byte except `id`/`esIdentite` — confirmed live this is
            // how the site mirrors shared-rubro promotions into "Identité".
            // Note there is NO fake for `rubro=Bodegas&esIdentite=false`:
            // Bodegas doesn't exist in the "Clásico" rubro list fixture, so
            // the scraper must never request that pair at all —
            // `preventStrayRequests()` would fail the test otherwise.
            'www.supervielle.com.ar/api/beneficios?rubro=Automotor&esIdentite=true' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/supervielle/beneficios_automotor_identite.json')),
            ),
            'www.supervielle.com.ar/api/beneficios?rubro=Turismo&esIdentite=true' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/supervielle/beneficios_turismo_identite.json')),
            ),
            'www.supervielle.com.ar/api/beneficios?rubro=Bodegas&esIdentite=true' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/supervielle/beneficios_bodegas_identite.json')),
            ),
            'www.supervielle.com.ar/api/beneficio?id=5814f1fca7edb507bb908d' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/supervielle/detalle_bridgestone.json')),
            ),
            'www.supervielle.com.ar/api/beneficio?id=5e14f1fca7edb507bb908d' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/supervielle/detalle_autoshop_modo.json')),
            ),
            // Confirmed live behaviour to guard against: a network failure
            // on the detail lookup must not drop the promo.
            'www.supervielle.com.ar/api/beneficio?id=5c0189a487f7aa01bc9290' => Http::response(null, 500),
            'www.supervielle.com.ar/api/beneficio?id=5f0885a487f7aa01bc9290' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/supervielle/detalle_sheraton.json')),
            ),
            'www.supervielle.com.ar/api/beneficio?id=5b0c9dcbbce6bd0fae8c' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/supervielle/detalle_alta_vista.json')),
            ),
            // The two "Identité" Automotor mirrors above must be dropped by
            // the content-signature dedup BEFORE stage 2 ever runs for them —
            // no fake exists for their own ids (5b14f1fca7edb507bb908d,
            // 5114f1fca7edb507bb908d), so `preventStrayRequests()` would fail
            // the test if the dedup let either one through.
        ]);

        $scraper = new SupervielleScraper;
        $promotions = iterator_to_array($scraper->scrape());

        $this->assertSame('supervielle', $scraper->walletSlug());
        $this->assertCount(5, $promotions);

        // Bridgestone: pure installments promo, no descuento at all.
        $bridgestone = $promotions[0];
        $this->assertSame('Bridgestone', $bridgestone->merchantName);
        $this->assertSame('12 cuotas sin interés', $bridgestone->title);
        $this->assertSame(
            'https://content-us-7.content-cms.com/8ba19f21-9a97-4525-8886-f54d823a5cea/dxdam/logos-beneficios/bridgestone.png',
            $bridgestone->merchantIconUrl,
        );
        $this->assertSame('Automotor', $bridgestone->category);
        $this->assertNull($bridgestone->discountPercentage);
        $this->assertNull($bridgestone->cashbackPercentage);
        $this->assertSame(12, $bridgestone->installments);
        $this->assertNull($bridgestone->reimbursementCap);
        $this->assertSame(['Todos los días'], $bridgestone->validDays);
        $this->assertNotNull($bridgestone->startDate);
        $this->assertSame('2026-04-01', $bridgestone->startDate->format('Y-m-d'));
        $this->assertNotNull($bridgestone->endDate);
        $this->assertSame('2026-11-30', $bridgestone->endDate->format('Y-m-d'));
        $this->assertStringContainsString('CARTERA CONSUMO', $bridgestone->terms);
        $this->assertSame('https://www.supervielle.com.ar/personas/beneficios/detalle/5814f1fca7edb507bb908d', $bridgestone->url);
        $this->assertSame('5814f1fca7edb507bb908d', $bridgestone->externalId);
        $this->assertSame(['Tarjeta de crédito'], $bridgestone->paymentMethods);
        $this->assertSame([], $bridgestone->locations);

        // Autoshop con MODO: `legales` says "ahorro", never "reintegro" —
        // classified as an instant discount, not cashback, despite being a
        // MODO-branded promo.
        $autoshop = $promotions[1];
        $this->assertSame('Autoshop con MODO', $autoshop->merchantName);
        $this->assertSame('15% de descuento', $autoshop->title);
        $this->assertSame(15.0, $autoshop->discountPercentage);
        $this->assertNull($autoshop->cashbackPercentage);
        $this->assertNull($autoshop->installments);
        $this->assertNull($autoshop->reimbursementCap);
        $this->assertSame(['Martes', 'Miércoles', 'Jueves'], $autoshop->validDays);
        $this->assertSame(['Tarjeta de crédito', 'Tarjeta de débito', 'MODO'], $autoshop->paymentMethods);
        $this->assertSame('5e14f1fca7edb507bb908d', $autoshop->externalId);

        // Aerolíneas Argentinas: the detail lookup fails outright (HTTP
        // 500) — the promo must survive with the listing's own fields;
        // dates still come from the listing directly (no detail needed for
        // those on this wallet), terms simply stays null.
        $aerolineas = $promotions[2];
        $this->assertSame('Aerolíneas Argentinas', $aerolineas->merchantName);
        $this->assertSame('6 cuotas sin interés', $aerolineas->title);
        $this->assertSame('Turismo', $aerolineas->category);
        $this->assertNull($aerolineas->discountPercentage);
        $this->assertNull($aerolineas->cashbackPercentage);
        $this->assertSame(6, $aerolineas->installments);
        $this->assertSame(['Todos los días'], $aerolineas->validDays);
        $this->assertNotNull($aerolineas->startDate);
        $this->assertSame('2026-06-01', $aerolineas->startDate->format('Y-m-d'));
        $this->assertNotNull($aerolineas->endDate);
        $this->assertSame('2026-11-30', $aerolineas->endDate->format('Y-m-d'));
        $this->assertNull($aerolineas->terms);
        $this->assertSame([], $aerolineas->locations);
        $this->assertSame('5c0189a487f7aa01bc9290', $aerolineas->externalId);

        // Sheraton Mendoza: "Identité"-exclusive-for-this-rubro promotion
        // (Turismo also exists under "Clásico", but this specific promotion
        // doesn't) — `legales` explicitly says "descuento vía reintegro",
        // so this is cashback, not a discount, despite being Visa-branded.
        $sheraton = $promotions[3];
        $this->assertSame('Sheraton Mendoza', $sheraton->merchantName);
        $this->assertSame('20% de reintegro y 12 cuotas sin interés', $sheraton->title);
        $this->assertNull($sheraton->discountPercentage);
        $this->assertSame(20.0, $sheraton->cashbackPercentage);
        $this->assertSame(12, $sheraton->installments);
        $this->assertSame(20000.0, $sheraton->reimbursementCap);
        $this->assertStringContainsString('reintegro', $sheraton->terms);
        $this->assertSame('5f0885a487f7aa01bc9290', $sheraton->externalId);

        // Alta Vista Wines con MODO: only reachable via the "Identité"
        // rubro list (Bodegas doesn't exist under "Clásico" at all) —
        // proves the union-of-both-segments' rubro sweep, not just the
        // task's own reference "automotor" category.
        $altaVista = $promotions[4];
        $this->assertSame('Alta Vista Wines con MODO', $altaVista->merchantName);
        $this->assertSame('Bodegas', $altaVista->category);
        $this->assertSame('30% de reintegro y 3 cuotas sin interés', $altaVista->title);
        $this->assertNull($altaVista->discountPercentage);
        $this->assertSame(30.0, $altaVista->cashbackPercentage);
        $this->assertSame(3, $altaVista->installments);
        $this->assertSame(30000.0, $altaVista->reimbursementCap);
        $this->assertSame(['Tarjeta de crédito', 'MODO'], $altaVista->paymentMethods);
        $this->assertSame('5b0c9dcbbce6bd0fae8c', $altaVista->externalId);
    }

    public function test_returns_no_promotions_when_both_segments_have_no_rubros(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'www.supervielle.com.ar/api/rubros?esIdentite=false' => Http::response([
                'codigo' => 'OK', 'rubros' => [],
            ]),
            'www.supervielle.com.ar/api/rubros?esIdentite=true' => Http::response([
                'codigo' => 'OK', 'rubros' => [],
            ]),
        ]);

        $promotions = iterator_to_array((new SupervielleScraper)->scrape());

        $this->assertCount(0, $promotions);
    }
}
