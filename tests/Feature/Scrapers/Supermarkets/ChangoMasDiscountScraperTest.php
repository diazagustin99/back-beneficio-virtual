<?php

namespace Tests\Feature\Scrapers\Supermarkets;

use App\Models\Wallet;
use App\Scrapers\Supermarkets\ChangoMasDiscountScraper;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChangoMasDiscountScraperTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fakeGraphQl(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'www.masonline.com.ar/_v/public/graphql/v1*' => function (Request $request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $fixture = ($query['operationName'] ?? '') === 'GetBanks' ? 'banks.json' : 'promos.json';

                return Http::response(
                    json_decode(file_get_contents(base_path("tests/Fixtures/Scrapers/changomas/{$fixture}")), true),
                );
            },
        ]);
    }

    public function test_parses_active_promos_and_resolves_bank_wallets(): void
    {
        $this->fakeGraphQl();

        $scraper = app(ChangoMasDiscountScraper::class);
        $promotions = iterator_to_array($scraper->scrape());

        $this->assertSame('ChangoMás', $scraper->merchantName());
        // Anses is a government agency, not a bank — dropped, not a wallet.
        $this->assertCount(3, $promotions);

        $galicia = $promotions[0];
        $this->assertSame('banco-galicia', $galicia->walletSlug);
        $this->assertSame(25.0, $galicia->discountPercentage);
        $this->assertNull($galicia->cashbackPercentage);
        $this->assertSame('25% de descuento Banco Galicia', $galicia->title);
        $this->assertSame(['Martes'], $galicia->validDays);
        $this->assertSame('Tope $10.000 por semana', $galicia->description);
        $this->assertSame(sha1('changomas|promo-galicia'), $galicia->externalId);

        // "de reintegro" is a cashback, not an immediate discount — same
        // distinction MercadoPagoScraper already makes from its own badges.
        $modo = $promotions[1];
        $this->assertSame('modo', $modo->walletSlug);
        $this->assertNull($modo->discountPercentage);
        $this->assertSame(20.0, $modo->cashbackPercentage);
        $this->assertSame('Desde MODO o APP de bancos adheridos', $modo->title);
        $this->assertSame(['Lunes'], $modo->validDays);

        // "Banco Credicoop MODO" resolves through the alias to the real
        // bank, and all 7 days explicitly true collapses to the sentinel.
        $credicoop = $promotions[2];
        $this->assertSame('banco-credicoop', $credicoop->walletSlug);
        $this->assertSame(12, $credicoop->installments);
        $this->assertSame('12 cuotas sin interés Banco Credicoop', $credicoop->title);
        $this->assertSame(['Todos los días'], $credicoop->validDays);

        $this->assertSame(3, Wallet::count());
        $this->assertTrue(Wallet::where('name', 'Banco Credicoop')->exists());
    }

    public function test_returns_no_promotions_when_the_feed_has_none(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'www.masonline.com.ar/_v/public/graphql/v1*' => Http::response(['data' => ['documents' => []]]),
        ]);

        $promotions = iterator_to_array(app(ChangoMasDiscountScraper::class)->scrape());

        $this->assertCount(0, $promotions);
    }
}
