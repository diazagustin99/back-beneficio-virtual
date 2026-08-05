<?php

namespace Tests\Feature\Scrapers;

use App\Scrapers\Credicoop\CredicoopScraper;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CredicoopScraperTest extends TestCase
{
    public function test_parses_every_promotion_enriching_one_with_locations_and_surviving_a_locations_failure(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'www.beneficios.bancocredicoop.coop/coop/beneficios/?p=search' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/credicoop/listing_bootstrap.html')),
                200,
                ['Set-Cookie' => 'PHPSESSID=testsession123; path=/; HTTPOnly; Secure'],
            ),
            'www.beneficios.bancocredicoop.coop/coop/beneficios/xapi_get_coop_active_benefits_general.php' => function (Request $request) {
                $search = $request->data()['data']['search'] ?? [];

                if (($search['p'] ?? null) === 'search') {
                    if (($search['offset'] ?? null) === 0) {
                        return Http::response(
                            file_get_contents(base_path('tests/Fixtures/Scrapers/credicoop/listing_page1.json')),
                        );
                    }

                    // Empty second page stops pagination.
                    return Http::response(['benefits' => [], 'benefits_total' => 3]);
                }

                if (($search['shop_id'] ?? null) === 1154) {
                    return Http::response(
                        file_get_contents(base_path('tests/Fixtures/Scrapers/credicoop/retails_63.json')),
                    );
                }

                // Confirmed live behaviour to guard against: a network
                // failure on the locations lookup must not drop the promo.
                if (($search['shop_id'] ?? null) === 8888) {
                    return Http::response(null, 500);
                }

                return Http::response(['benefits' => []]);
            },
        ]);

        $scraper = new CredicoopScraper;
        $promotions = iterator_to_array($scraper->scrape());

        $this->assertSame('credicoop', $scraper->walletSlug());
        $this->assertCount(3, $promotions);

        // Colorshop: full enrichment — cashback (reintegro), installments,
        // valid days, payment methods and a physical location survive, the
        // virtual/online placeholder retail is filtered out.
        $colorshop = $promotions[0];
        $this->assertSame('Colorshop', $colorshop->merchantName);
        $this->assertSame('10% de AHORRO y hasta 6 cuotas sin interés', $colorshop->title);
        $this->assertSame('Pinturerías', $colorshop->category);
        $this->assertStringContainsString('reintegro', $colorshop->description);
        $this->assertNull($colorshop->discountPercentage);
        $this->assertSame(10.0, $colorshop->cashbackPercentage);
        $this->assertSame(6, $colorshop->installments);
        $this->assertNull($colorshop->reimbursementCap);
        $this->assertSame(['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes'], $colorshop->validDays);
        $this->assertNull($colorshop->startDate);
        $this->assertNull($colorshop->endDate);
        $this->assertStringContainsString('60 días', $colorshop->terms);
        $this->assertSame(
            'https://www.beneficios.bancocredicoop.coop/coop/beneficios/?p=benefit&benefit_id=63&shop_id=1154&back=search',
            $colorshop->url,
        );
        $this->assertSame('63', $colorshop->externalId);
        $this->assertSame(['Cabal Crédito', 'Cabal Débito', 'MODO'], $colorshop->paymentMethods);
        $this->assertSame(
            'https://solme.soft-cake.net/files/4/medias/28f0b864598a1291557bed248a998d4e.jpg',
            $colorshop->merchantIconUrl,
        );
        $this->assertCount(1, $colorshop->locations);
        $this->assertSame('store', $colorshop->locations[0]->scope);
        $this->assertSame('Buenos Aires', $colorshop->locations[0]->province);
        $this->assertSame('25 De Mayo', $colorshop->locations[0]->city);
        $this->assertSame('Av. Santa Rosa 198', $colorshop->locations[0]->address);
        $this->assertSame(-34.6054996, $colorshop->locations[0]->latitude);
        $this->assertSame(-58.37109, $colorshop->locations[0]->longitude);

        // Bridgestone: pure "cuotas sin interés" promo with no discount
        // percentage at all, and `retails_count: 0` — the locations call
        // must never be made for it.
        $bridgestone = $promotions[1];
        $this->assertSame('Bridgestone', $bridgestone->merchantName);
        $this->assertSame('Hasta 6 cuotas sin interés', $bridgestone->title);
        $this->assertSame('Automóviles y Motos', $bridgestone->category);
        $this->assertNull($bridgestone->discountPercentage);
        $this->assertNull($bridgestone->cashbackPercentage);
        $this->assertSame(6, $bridgestone->installments);
        $this->assertSame(['Sábado'], $bridgestone->validDays);
        $this->assertSame(['Cabal Crédito'], $bridgestone->paymentMethods);
        $this->assertSame([], $bridgestone->locations);
        $this->assertSame('501', $bridgestone->externalId);

        // Cooperativa Obrera: `retails_count: 5` triggers the locations
        // call, but it fails outright (HTTP 500) — the promo must survive
        // with every other field intact and just an empty locations list,
        // never dropped or the whole scrape aborted.
        $cooperativaObrera = $promotions[2];
        $this->assertSame('Cooperativa Obrera', $cooperativaObrera->merchantName);
        $this->assertSame('Hasta 40% de AHORRO', $cooperativaObrera->title);
        $this->assertSame('Supermercado', $cooperativaObrera->category);
        $this->assertNull($cooperativaObrera->discountPercentage);
        $this->assertSame(40.0, $cooperativaObrera->cashbackPercentage);
        $this->assertSame(18000.0, $cooperativaObrera->reimbursementCap);
        $this->assertSame(['Sábado'], $cooperativaObrera->validDays);
        $this->assertSame(['Cabal Crédito', 'Cabal Débito', 'MODO (exclusivo)'], $cooperativaObrera->paymentMethods);
        $this->assertSame(
            'https://www.beneficios.bancocredicoop.coop/coop/beneficios/images/logos200/cooperativa-obrera.jpg',
            $cooperativaObrera->merchantIconUrl,
        );
        $this->assertSame([], $cooperativaObrera->locations);
        $this->assertSame('777', $cooperativaObrera->externalId);

        // The session bootstrapped from the listing page's own markup must
        // actually be replayed on every subsequent API call.
        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'xapi_get_coop_active_benefits_general')) {
                return false;
            }

            $csrfToken = $request->data()['data']['csrf_token'] ?? null;

            return $request->header('Cookie')[0] === 'PHPSESSID=testsession123'
                && $csrfToken === '34393836653834303366313537353032323862336131643961356235346162316332626532663738643837643439353235363664373834323837336437633230';
        });
    }

    public function test_returns_no_promotions_when_the_listing_has_none(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'www.beneficios.bancocredicoop.coop/coop/beneficios/?p=search' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/credicoop/listing_bootstrap.html')),
                200,
                ['Set-Cookie' => 'PHPSESSID=testsession123; path=/; HTTPOnly; Secure'],
            ),
            'www.beneficios.bancocredicoop.coop/coop/beneficios/xapi_get_coop_active_benefits_general.php' => Http::response(
                ['benefits' => [], 'benefits_total' => 0],
            ),
        ]);

        $promotions = iterator_to_array((new CredicoopScraper)->scrape());

        $this->assertCount(0, $promotions);
    }
}
