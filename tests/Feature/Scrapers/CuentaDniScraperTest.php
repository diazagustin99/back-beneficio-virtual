<?php

namespace Tests\Feature\Scrapers;

use App\Scrapers\CuentaDni\CuentaDniScraper;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CuentaDniScraperTest extends TestCase
{
    public function test_parses_every_visible_benefit_across_the_known_rubros(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*GetBeneficioByRubro?idRubro=1' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/cuenta_dni/rubro_1.json')),
            ),
            '*GetBeneficioByRubro?idRubro=2' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/cuenta_dni/rubro_2.json')),
            ),
            '*GetBeneficioByRubro*' => Http::response('[]'),
        ]);

        $scraper = new CuentaDniScraper;
        $promotions = iterator_to_array($scraper->scrape());

        $this->assertSame('cuenta_dni', $scraper->walletSlug());
        // Rubro 1 has 3 items: one `oculto: 1` and one already expired
        // (`fecha_hasta` in the past) — both must be skipped.
        $this->assertCount(2, $promotions);
        $this->assertTrue(
            collect($promotions)->every(fn ($promotion) => $promotion->merchantName !== 'PROMO VENCIDA DEMO'),
        );

        $varios = $promotions[0];
        $this->assertSame('TIENDA DEMO VARIOS', $varios->merchantName);
        $this->assertSame('TODO FEBRERO', $varios->title);
        $this->assertSame('https://www.bancoprovincia.com.ar/CDN/Get/otros_rubros_cdni', $varios->merchantIconUrl);
        $this->assertSame('Varios', $varios->category);
        $this->assertSame(30.0, $varios->discountPercentage);
        $this->assertSame('82', $varios->externalId);
        $this->assertNotNull($varios->startDate);
        $this->assertNotNull($varios->endDate);
        $this->assertSame('Promoción de demostración con términos y condiciones.', $varios->terms);

        $garrafas = $promotions[1];
        $this->assertSame('GASERA DEMO', $garrafas->merchantName);
        $this->assertSame('Garrafas', $garrafas->category);
        $this->assertSame(10.0, $garrafas->discountPercentage);
    }

    public function test_discards_items_whose_expiry_date_has_already_passed(): void
    {
        $dotNetDate = fn (\DateTimeInterface $date) => '/Date('.($date->getTimestamp() * 1000).')/';

        $expired = [
            'id' => 1,
            'titulo' => 'PROMO VENCIDA',
            'porcentaje' => 50,
            'fecha_desde' => $dotNetDate(now()->subMonths(2)),
            'fecha_hasta' => $dotNetDate(now()->subDay()),
            'oculto' => 0,
        ];
        $expiresToday = [
            'id' => 2,
            'titulo' => 'PROMO VENCE HOY',
            'porcentaje' => 20,
            'fecha_desde' => $dotNetDate(now()->subMonth()),
            'fecha_hasta' => $dotNetDate(now()),
            'oculto' => 0,
        ];
        $stillValid = [
            'id' => 3,
            'titulo' => 'PROMO VIGENTE',
            'porcentaje' => 15,
            'fecha_desde' => $dotNetDate(now()->subMonth()),
            'fecha_hasta' => $dotNetDate(now()->addMonth()),
            'oculto' => 0,
        ];

        Http::preventStrayRequests();
        Http::fake([
            '*GetBeneficioByRubro?idRubro=1' => Http::response([$expired, $expiresToday, $stillValid]),
            '*GetBeneficioByRubro*' => Http::response('[]'),
        ]);

        $promotions = iterator_to_array((new CuentaDniScraper)->scrape());
        $names = array_map(fn ($promotion) => $promotion->merchantName, $promotions);

        $this->assertNotContains('PROMO VENCIDA', $names);
        $this->assertContains('PROMO VENCE HOY', $names);
        $this->assertContains('PROMO VIGENTE', $names);
    }

    public function test_returns_no_promotions_when_every_rubro_is_empty(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*GetBeneficioByRubro*' => Http::response('[]'),
        ]);

        $promotions = iterator_to_array((new CuentaDniScraper)->scrape());

        $this->assertCount(0, $promotions);
    }
}
