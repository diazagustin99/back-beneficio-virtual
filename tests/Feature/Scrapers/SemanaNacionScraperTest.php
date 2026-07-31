<?php

namespace Tests\Feature\Scrapers;

use App\Scrapers\SemanaNacion\SemanaNacionScraper;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SemanaNacionScraperTest extends TestCase
{
    public function test_maps_every_brand_of_every_valid_promotion(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'backend.activx.production.digiventures.la/api/categories*' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/bna/categories.json')),
            ),
            'backend.activx.production.digiventures.la/api/brands?*' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/bna/brands.json')),
            ),
            'backend.activx.production.digiventures.la/api/brands/with-promotions*' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/bna/brands_with_promotions.json')),
            ),
            'semananacion.com.ar/semananacion/farmademo' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/bna/farmademo.html')),
            ),
            'semananacion.com.ar/semananacion/ropademo' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/bna/ropademo.html')),
            ),
            'semananacion.com.ar/semananacion/combustibledemo' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Scrapers/bna/combustibledemo.html')),
            ),
        ]);

        $scraper = new SemanaNacionScraper;
        $promotions = iterator_to_array($scraper->scrape());

        $this->assertSame('bna', $scraper->walletSlug());
        $this->assertCount(3, $promotions);

        $cashback = $promotions[0];
        $this->assertSame('Farmademo', $cashback->merchantName);
        $this->assertSame('Farmacias', $cashback->title);
        $this->assertSame('https://cdn.example.com/farmademo.png', $cashback->merchantIconUrl);
        $this->assertSame('Farmacias', $cashback->category);
        $this->assertNull($cashback->discountPercentage);
        $this->assertSame(20.0, $cashback->cashbackPercentage);
        $this->assertSame(5000.0, $cashback->reimbursementCap);
        $this->assertNull($cashback->installments);
        $this->assertNotNull($cashback->startDate);
        $this->assertNotNull($cashback->endDate);
        $this->assertSame('https://semananacion.com.ar/semananacion/farmademo', $cashback->url);
        $this->assertSame('promo-farmademo:brand-farmademo', $cashback->externalId);
        $this->assertSame('Términos y condiciones de Farmademo. Válido en todo el país.', $cashback->terms);
        // Enriched from the same landing-page fetch, via its `offer` component.
        $this->assertSame(['Lunes', 'Miércoles', 'Viernes'], $cashback->validDays);
        $this->assertSame(['Visa Credit', 'Mc Debit'], $cashback->paymentMethods);

        $installments = $promotions[1];
        $this->assertSame('Ropa Demo', $installments->merchantName);
        $this->assertNull($installments->category);
        $this->assertNull($installments->discountPercentage);
        $this->assertNull($installments->cashbackPercentage);
        $this->assertSame(6, $installments->installments);
        // The listing HTML wraps this in <b>/<br> tags — must arrive as clean
        // plain text, not raw markup.
        $this->assertSame("Términos y condiciones de Ropa Demo.\nVálido en todo el país.", $installments->terms);
        // This brand's landing page groups several promo variants instead of
        // having its own `offer` component — must degrade to empty, not fail.
        $this->assertSame([], $installments->validDays);
        $this->assertSame([], $installments->paymentMethods);

        /**
         * Regression test for a real incident found live (Shell's own
         * landing page): some brands have no `termsAndConditions` field at
         * all — their only copy of the legal text lives inside a
         * `multiaccordionv2` accordion further down the same page. That
         * component type is also reused for unrelated widgets (a store
         * locator here) — the real terms step must still be found by its
         * "bases y condiciones" subtitle, not just taken as the first one.
         */
        $combustible = $promotions[2];
        $this->assertSame('Combustible Demo', $combustible->merchantName);
        $this->assertSame(
            "Términos legales de Combustible Demo.\nTope \$3.000 por cliente por semana.",
            $combustible->terms,
        );
    }

    public function test_returns_no_promotions_when_the_brand_catalog_is_empty(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'backend.activx.production.digiventures.la/api/categories*' => Http::response('[]'),
            'backend.activx.production.digiventures.la/api/brands?*' => Http::response('[]'),
        ]);

        $promotions = iterator_to_array((new SemanaNacionScraper)->scrape());

        $this->assertCount(0, $promotions);
    }
}
