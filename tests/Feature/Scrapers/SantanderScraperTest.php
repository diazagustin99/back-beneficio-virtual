<?php

namespace Tests\Feature\Scrapers;

use App\Scrapers\Santander\SantanderScraper;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SantanderScraperTest extends TestCase
{
    public function test_parses_every_promotion_enriching_one_and_falling_back_when_a_stage_fails(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'www.santander.com.ar/bff-benefits/brands?*' => function (Request $request) {
                $page = (int) ($request->data()['page'] ?? 1);

                if ($page === 1) {
                    return Http::response(
                        file_get_contents(base_path('tests/Fixtures/Scrapers/santander/brands_page1.json')),
                    );
                }

                // Empty second page stops pagination.
                return Http::response(['items' => [], 'totalItems' => 3]);
            },
            'www.santander.com.ar/bff-benefits/brands/131' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/santander/programs_131.json')),
            ),
            'www.santander.com.ar/bff-benefits/brands/184' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/santander/programs_184.json')),
            ),
            // Confirmed live behaviour: stage 2 can fail outright for a
            // brand — best-effort fallback to stage 1's own bare summary.
            'www.santander.com.ar/bff-benefits/brands/267' => Http::response(null, 500),
            // Stage 3 (cards + physical locations) fails for Coto — the
            // promo must survive with its stage 2 fields only.
            'www.santander.com.ar/bff-benefits/publications/7476*' => Http::response(null, 500),
            'www.santander.com.ar/bff-benefits/publications/7586*' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/santander/publication_7586_brand184.json')),
            ),
        ]);

        $scraper = new SantanderScraper;
        $promotions = iterator_to_array($scraper->scrape());

        $this->assertSame('santander', $scraper->walletSlug());
        $this->assertCount(3, $promotions);

        // Coto: stage 2 succeeds, stage 3 (enrichment) fails — the promo
        // still carries every stage 2 field, just no cards/locations.
        $coto = $promotions[0];
        $this->assertSame('Coto', $coto->merchantName);
        $this->assertSame('30% de ahorro', $coto->title);
        $this->assertSame(
            'https://microfe.santander.com.ar/premify/benefits/assets/images/Coto_desktopImage.png',
            $coto->merchantIconUrl,
        );
        $this->assertSame('Supermercados', $coto->category);
        $this->assertSame('Sin tope de reintegro', $coto->description);
        $this->assertSame(30.0, $coto->discountPercentage);
        $this->assertNull($coto->cashbackPercentage);
        $this->assertNull($coto->installments);
        $this->assertNull($coto->reimbursementCap);
        $this->assertSame(['Jueves'], $coto->validDays);
        $this->assertNotNull($coto->startDate);
        $this->assertSame('2026-07-16', $coto->startDate->format('Y-m-d'));
        $this->assertNotNull($coto->endDate);
        $this->assertSame('2026-08-27', $coto->endDate->format('Y-m-d'));
        $this->assertStringContainsString('Exclusivo pagando sin contacto', $coto->terms);
        $this->assertStringContainsString('Promoción vigente en la Ciudad Autónoma de Buenos Aires', $coto->terms);
        $this->assertSame('https://www.santander.com.ar/personas/beneficios#/brand?brandId=131&programId=7476', $coto->url);
        $this->assertSame('131-7476', $coto->externalId);
        $this->assertSame([], $coto->paymentMethods);
        $this->assertSame([], $coto->locations);

        // Farmacity: stage 2 and stage 3 both succeed — full enrichment,
        // including the cashback-vs-discount ("reintegro") distinction, the
        // real card list, and this brand's own subset of a shared
        // multi-brand publication's establishments (not The Food Market's).
        $farmacity = $promotions[1];
        $this->assertSame('Farmacity', $farmacity->merchantName);
        $this->assertSame('25% de ahorro + hasta 3 cuotas sin interés', $farmacity->title);
        $this->assertSame('Farmacias', $farmacity->category);
        $this->assertSame('Tope de reintegro: $15.000 mensual', $farmacity->description);
        $this->assertNull($farmacity->discountPercentage);
        $this->assertSame(25.0, $farmacity->cashbackPercentage);
        $this->assertSame(3, $farmacity->installments);
        $this->assertSame(15000.0, $farmacity->reimbursementCap);
        $this->assertSame(['Martes'], $farmacity->validDays);
        $this->assertSame('2026-08-01', $farmacity->startDate->format('Y-m-d'));
        $this->assertSame('2026-09-30', $farmacity->endDate->format('Y-m-d'));
        $this->assertSame('184-7586', $farmacity->externalId);
        $this->assertSame(
            'https://www.santander.com.ar/personas/beneficios#/brand?brandId=184&programId=7586',
            $farmacity->url,
        );
        $this->assertSame(['VISA Débito', 'VISA Crédito'], $farmacity->paymentMethods);
        $this->assertCount(2, $farmacity->locations);
        $this->assertSame('store', $farmacity->locations[0]->scope);
        $this->assertSame('C.A.B.A', $farmacity->locations[0]->province);
        $this->assertSame('C.A.B.A', $farmacity->locations[0]->city);
        $this->assertSame('Carlos Pellegrini 457, e/Av. Corrientes y Lavalle', $farmacity->locations[0]->address);
        $this->assertSame('FARMACITY', $farmacity->locations[0]->storeName);
        $this->assertNull($farmacity->locations[0]->latitude);

        // Open Sports: stage 2 fails outright — best-effort fallback built
        // purely from stage 1's own bare "20%" summary string.
        $openSports = $promotions[2];
        $this->assertSame('Open Sports', $openSports->merchantName);
        $this->assertSame('20%', $openSports->title);
        $this->assertSame(20.0, $openSports->discountPercentage);
        $this->assertNull($openSports->cashbackPercentage);
        $this->assertNull($openSports->category);
        $this->assertSame([], $openSports->validDays);
        $this->assertNull($openSports->startDate);
        $this->assertSame('https://www.santander.com.ar/personas/beneficios#/brand?brandId=267', $openSports->url);
        $this->assertSame('267', $openSports->externalId);
        $this->assertSame([], $openSports->paymentMethods);
        $this->assertSame([], $openSports->locations);
    }

    public function test_returns_no_promotions_when_the_listing_has_none(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'www.santander.com.ar/bff-benefits/brands?*' => Http::response(['items' => [], 'totalItems' => 0]),
        ]);

        $promotions = iterator_to_array((new SantanderScraper)->scrape());

        $this->assertCount(0, $promotions);
    }
}
