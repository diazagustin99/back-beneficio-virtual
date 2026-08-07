<?php

namespace Tests\Feature\Scrapers;

use App\Scrapers\Modo\ModoScraper;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ModoScraperTest extends TestCase
{
    /**
     * Builds a minimal detail page carrying only what `ModoScraper` reads
     * out of the real one: `trigger_params` (always required for the
     * enrichment to be considered usable at all), `promotion_type`, and
     * `banks`.
     *
     * @param  array<string, mixed>  $triggerParams
     * @param  list<array<string, mixed>>  $banks
     */
    private function detailHtml(?string $promotionType, array $banks, array $triggerParams = ['min_amount' => null]): string
    {
        $payload = [
            'trigger_params' => $triggerParams,
            'sections' => [],
            'promotion_type' => $promotionType,
            'banks' => $banks,
        ];

        // The real page's chunk is a JSON *string* whose decoded content is
        // itself a JS object literal — so `$payload` is encoded once to get
        // that object literal, then encoded again (as a plain string) to
        // get the escaped form that belongs between the literal quotes
        // `push([1,"..."])` already has in the HTML below.
        $objectLiteral = json_encode($payload);
        $escapedInner = substr(json_encode($objectLiteral), 1, -1);

        return '<html><body><script>self.__next_f.push([1,"'.$escapedInner.'"])</script></body></html>';
    }

    public function test_paginates_through_every_page_and_maps_fields(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*rewards/categories*' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/modo/categories.json')),
            ),
            '*rewards/slots*page=1*' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/modo/page_1.json')),
            ),
            '*rewards/slots*page=2*' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/modo/page_2.json')),
            ),
            'www.modo.com.ar/promos/20off-restodemo' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/modo/detail_resto_demo.html')),
            ),
            // A detail page that responds but without the expected embedded
            // payload — the enrichment must degrade to the listing fields.
            'www.modo.com.ar/promos/15off-superdemo' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/modo/detail_super_demo_broken.html')),
            ),
        ]);

        $scraper = new ModoScraper;
        $promotions = iterator_to_array($scraper->scrape());

        $this->assertSame('modo', $scraper->walletSlug());
        $this->assertCount(2, $promotions);

        $first = $promotions[0];
        $this->assertSame('Restó Demo', $first->merchantName);
        $this->assertSame('20% en Restó Demo', $first->title);
        $this->assertSame('https://assets.example.com/demo/resto-demo.jpg', $first->merchantIconUrl);
        $this->assertSame('Gastronomía', $first->category);
        $this->assertSame(['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'], $first->validDays);
        $this->assertSame('promo-demo-0001', $first->externalId);
        $this->assertNotNull($first->startDate);
        $this->assertNotNull($first->endDate);
        // Enriched from the detail page (see detail_resto_demo.html):
        // "20% en Restó Demo" is a bank cashback, not a merchant discount.
        $this->assertNull($first->discountPercentage);
        $this->assertSame(20.0, $first->cashbackPercentage);
        $this->assertSame(3000.0, $first->reimbursementCap);
        $this->assertSame(5000.0, $first->minimumPurchase);
        $this->assertSame(6, $first->installments);
        $this->assertSame(['Visa', 'Master'], $first->paymentMethods);
        $this->assertSame(
            "¿Cómo puedo acceder al beneficio?\nComprando en Restó Demo con MODO.\nEs acumulable con otras promociones ¡Sí!",
            $first->description,
        );

        $second = $promotions[1];
        $this->assertSame('Super Demo', $second->merchantName);
        $this->assertSame('Mercados', $second->category);
        $this->assertSame(['Martes', 'Jueves'], $second->validDays);
        // The detail page for this one has no usable payload — must fall
        // back to the listing's own fields instead of losing data.
        $this->assertSame(15.0, $second->discountPercentage);
        $this->assertNull($second->cashbackPercentage);
        $this->assertNull($second->reimbursementCap);
        $this->assertNull($second->minimumPurchase);
        $this->assertNull($second->installments);
        $this->assertSame(['Visa Debit'], $second->paymentMethods);
        $this->assertSame('2507-Demo-SuperDemo-Presencial-15off', $second->description);
    }

    public function test_returns_no_promotions_on_an_empty_catalog(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*rewards/categories*' => Http::response('[]'),
            '*rewards/slots*' => Http::response(json_encode([
                'data' => ['cards' => []],
                'metadata' => ['pagination' => ['page' => 1, 'page_results' => 0, 'total_pages' => 0, 'total_results' => 0]],
            ])),
        ]);

        $promotions = iterator_to_array((new ModoScraper)->scrape());

        $this->assertCount(0, $promotions);
    }

    public function test_the_scrape_still_completes_if_every_detail_page_request_fails(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*rewards/categories*' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/modo/categories.json')),
            ),
            '*rewards/slots*page=1*' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/modo/page_1.json')),
            ),
            '*rewards/slots*page=2*' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/modo/page_2.json')),
            ),
            'www.modo.com.ar/promos/*' => Http::response('Internal Server Error', 500),
        ]);

        $promotions = iterator_to_array((new ModoScraper)->scrape());

        $this->assertCount(2, $promotions);
        $this->assertSame('Restó Demo', $promotions[0]->merchantName);
        $this->assertSame('2507-Demo-RestoDemo-Presencial-20off', $promotions[0]->description);
        $this->assertNull($promotions[0]->reimbursementCap);
    }

    /**
     * Regression test for a real incident found live: on the real site,
     * ~5% of MODO's catalog doesn't inline its detail-page body text at all
     * — `sections.body.contents[0].description` is itself a bare React
     * Flight back-reference placeholder like `"$25"`, pointing at a
     * separately-numbered chunk this scraper never resolves. Left
     * unguarded, that literal string ("$25") landed in the database as if
     * it were the promo's description.
     */
    public function test_a_bare_flight_reference_placeholder_never_becomes_the_description(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*rewards/categories*' => Http::response('[]'),
            '*rewards/slots*' => Http::response(json_encode([
                'data' => ['cards' => [[
                    'id' => 'demo-0003',
                    'title' => '20% en City Kids Demo',
                    'where' => 'City Kids Demo',
                    'short_description' => '2601-Demo-CityKidsDemo-Online-20off',
                    'start_date' => '2026-01-01T00:00:00.000Z',
                    'stop_date' => '2026-12-31T00:00:00.000Z',
                    'days_of_week' => 'LMXJVSD',
                    'status' => 'active',
                    'promo_id' => 'promo-demo-0003',
                    'slug' => 'citykids-demo',
                    'calculated_status' => 'RUNNING',
                    'debit_list' => [],
                    'credit_list' => ['visa'],
                ]]],
                'metadata' => ['pagination' => ['page' => 1, 'page_results' => 1, 'total_pages' => 1, 'total_results' => 1]],
            ])),
            'www.modo.com.ar/promos/citykids-demo' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/modo/detail_citykids_demo_dollar_reference.html')),
            ),
        ]);

        $promotions = iterator_to_array((new ModoScraper)->scrape());

        $this->assertCount(1, $promotions);
        $promotion = $promotions[0];
        // Falls back to the listing's own description instead of "$25".
        $this->assertSame('2601-Demo-CityKidsDemo-Online-20off', $promotion->description);
        // The rest of the detail payload is still valid and must still be used.
        $this->assertSame(20.0, $promotion->cashbackPercentage);
        $this->assertSame(20000.0, $promotion->reimbursementCap);
    }

    /**
     * Confirmed live: MODO isn't itself a bank — a "Bancaria" promo's own
     * `banks` array names exactly which bank it's exclusive to, and that
     * bank gets its own promotion row (tagged "MODO" as a payment method)
     * instead of one under `modo`.
     */
    public function test_a_promo_exclusive_to_a_bank_we_scrape_is_attributed_to_that_banks_wallet(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*rewards/categories*' => Http::response('[]'),
            '*rewards/slots*' => Http::response(json_encode([
                'data' => ['cards' => [[
                    'id' => 'demo-macro',
                    'title' => '20% en Alba la Pérgola',
                    'where' => 'Alba la Pérgola',
                    'short_description' => '2507-Macro-AlbaLaPergola-20off',
                    'days_of_week' => 'LMXJVSD',
                    'status' => 'active',
                    'promo_id' => 'promo-demo-macro',
                    'slug' => 'macro-exclusive-demo',
                    'calculated_status' => 'RUNNING',
                    'debit_list' => [],
                    'credit_list' => ['visa'],
                ]]],
                'metadata' => ['pagination' => ['page' => 1, 'page_results' => 1, 'total_pages' => 1, 'total_results' => 1]],
            ])),
            'www.modo.com.ar/promos/macro-exclusive-demo' => Http::response($this->detailHtml(
                'Bancaria',
                [['hub_bank_id' => 'macro', 'name' => 'Macro']],
            )),
        ]);

        $promotions = iterator_to_array((new ModoScraper)->scrape());

        $this->assertCount(1, $promotions);
        $this->assertSame('macro', $promotions[0]->walletSlug);
        $this->assertContains('MODO', $promotions[0]->paymentMethods);
    }

    /**
     * Every bank MODO currently partners with has a wallet of ours (see
     * `WalletSeeder`) — 8 with their own scraper, the rest attribution-only.
     * "Comafi" is one of the attribution-only ones: no scraper of its own,
     * but a "Bancaria" promo naming it still gets attributed to it.
     */
    public function test_a_promo_exclusive_to_an_attribution_only_banks_wallet_is_attributed_there(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*rewards/categories*' => Http::response('[]'),
            '*rewards/slots*' => Http::response(json_encode([
                'data' => ['cards' => [[
                    'id' => 'demo-comafi',
                    'title' => '10% en Farmacias',
                    'where' => 'Farmacias',
                    'short_description' => '2507-Comafi-Farmacias-10off',
                    'days_of_week' => 'LMXJVSD',
                    'status' => 'active',
                    'promo_id' => 'promo-demo-comafi',
                    'slug' => 'comafi-exclusive-demo',
                    'calculated_status' => 'RUNNING',
                    'debit_list' => [],
                    'credit_list' => ['visa'],
                ]]],
                'metadata' => ['pagination' => ['page' => 1, 'page_results' => 1, 'total_pages' => 1, 'total_results' => 1]],
            ])),
            'www.modo.com.ar/promos/comafi-exclusive-demo' => Http::response($this->detailHtml(
                'Bancaria',
                [['hub_bank_id' => 'comafi', 'name' => 'Comafi']],
            )),
        ]);

        $promotions = iterator_to_array((new ModoScraper)->scrape());

        $this->assertCount(1, $promotions);
        $this->assertSame('comafi', $promotions[0]->walletSlug);
        $this->assertContains('MODO', $promotions[0]->paymentMethods);
    }

    /**
     * If MODO ever partners with a bank not yet in `BANK_WALLET_SLUGS`
     * (every *current* partner bank already is one), that promo has
     * nowhere of its own to go yet — it must fall back to `modo` exactly
     * like a non-exclusive one would, not get lost.
     */
    public function test_a_promo_exclusive_to_an_unmapped_bank_falls_back_to_modo(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*rewards/categories*' => Http::response('[]'),
            '*rewards/slots*' => Http::response(json_encode([
                'data' => ['cards' => [[
                    'id' => 'demo-unmapped',
                    'title' => '10% en Farmacias',
                    'where' => 'Farmacias',
                    'short_description' => '2507-Unmapped-Farmacias-10off',
                    'days_of_week' => 'LMXJVSD',
                    'status' => 'active',
                    'promo_id' => 'promo-demo-unmapped',
                    'slug' => 'unmapped-bank-demo',
                    'calculated_status' => 'RUNNING',
                    'debit_list' => [],
                    'credit_list' => ['visa'],
                ]]],
                'metadata' => ['pagination' => ['page' => 1, 'page_results' => 1, 'total_pages' => 1, 'total_results' => 1]],
            ])),
            'www.modo.com.ar/promos/unmapped-bank-demo' => Http::response($this->detailHtml(
                'Bancaria',
                [['hub_bank_id' => 'a_future_bank_modo_has_not_announced_yet', 'name' => 'Banco Futuro']],
            )),
        ]);

        $promotions = iterator_to_array((new ModoScraper)->scrape());

        $this->assertCount(1, $promotions);
        $this->assertSame('modo', $promotions[0]->walletSlug);
    }

    /**
     * Confirmed live: a "Bancos adheridos" (any affiliated bank) promo's own
     * `banks` array lists *every* one of MODO's partner banks, not a
     * specific one — its `promotion_type` is never "Bancaria" ("Comercio"
     * in this case), which is what actually distinguishes it from a
     * genuinely exclusive promo. Must stay under `modo`.
     */
    public function test_a_promo_valid_with_any_affiliated_bank_stays_under_modo(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*rewards/categories*' => Http::response('[]'),
            '*rewards/slots*' => Http::response(json_encode([
                'data' => ['cards' => [[
                    'id' => 'demo-anybank',
                    'title' => '30% en Farmacias Gassmann',
                    'where' => 'Farmacias Gassmann',
                    'short_description' => '2507-Gassmann-30off',
                    'days_of_week' => 'LMXJVSD',
                    'status' => 'active',
                    'promo_id' => 'promo-demo-anybank',
                    'slug' => 'anybank-demo',
                    'calculated_status' => 'RUNNING',
                    'debit_list' => [],
                    'credit_list' => ['visa'],
                ]]],
                'metadata' => ['pagination' => ['page' => 1, 'page_results' => 1, 'total_pages' => 1, 'total_results' => 1]],
            ])),
            'www.modo.com.ar/promos/anybank-demo' => Http::response($this->detailHtml(
                'Comercio',
                [
                    ['hub_bank_id' => 'macro', 'name' => 'Macro'],
                    ['hub_bank_id' => 'nacion', 'name' => 'Banco Nación'],
                    ['hub_bank_id' => 'galicia', 'name' => 'Galicia'],
                ],
            )),
        ]);

        $promotions = iterator_to_array((new ModoScraper)->scrape());

        $this->assertCount(1, $promotions);
        $this->assertSame('modo', $promotions[0]->walletSlug);
        $this->assertNotContains('MODO', $promotions[0]->paymentMethods);
    }
}
