<?php

namespace Tests\Feature\Scrapers\Supermarkets;

use App\Actions\Scraping\ResolveWalletFromBankNameAction;
use App\Models\Wallet;
use App\Scrapers\Supermarkets\CarrefourDiscountScraper;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CarrefourDiscountScraperTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function scraperWithFixture(): CarrefourDiscountScraper
    {
        $html = file_get_contents(base_path('tests/Fixtures/Scrapers/carrefour/descuentos-bancarios.html'));

        return new class(app(ResolveWalletFromBankNameAction::class), $html) extends CarrefourDiscountScraper
        {
            public function __construct(ResolveWalletFromBankNameAction $resolveWallet, private readonly string $fixtureHtml)
            {
                parent::__construct($resolveWallet);
            }

            protected function renderHtml(): string
            {
                return $this->fixtureHtml;
            }
        };
    }

    public function test_parses_every_card_it_can_attribute_to_a_wallet(): void
    {
        $promotions = iterator_to_array($this->scraperWithFixture()->scrape());

        // 6 cards in the fixture: a Mi Carrefour/Anses eligibility card
        // with no payment method at all, and an explicit "todos los medios
        // de pago" card, are both dropped — neither is attributable to one
        // specific wallet.
        $this->assertCount(4, $promotions);

        $carrefourBanco = $promotions[0];
        $this->assertSame('carrefour-banco', $carrefourBanco->walletSlug);
        $this->assertSame(20.0, $carrefourBanco->discountPercentage);
        $this->assertNull($carrefourBanco->installments);
        $this->assertSame(['Jueves'], $carrefourBanco->validDays);

        $mercadoPago = $promotions[1];
        $this->assertSame('mercado-pago', $mercadoPago->walletSlug);
        $this->assertNull($mercadoPago->discountPercentage);
        $this->assertSame(6, $mercadoPago->installments);
        $this->assertSame(['Lunes', 'Miércoles'], $mercadoPago->validDays);

        // Only its image filename ("Promo-bancaria_patagonia.webp") names
        // the bank — the visible text never says "Banco Patagonia" — and a
        // "martes a domingo" range expands to every day but Monday.
        $patagonia = $promotions[2];
        $this->assertSame('banco-patagonia', $patagonia->walletSlug);
        $this->assertSame(15.0, $patagonia->discountPercentage);
        $this->assertSame(['Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'], $patagonia->validDays);

        // Its title only says "Modo", but the logo file names the
        // issuing bank ("bna-logo.png") — the underlying bank wins over
        // the bare payment channel.
        $bna = $promotions[3];
        $this->assertSame('banco-nacion', $bna->walletSlug);
        $this->assertSame(5.0, $bna->discountPercentage);
        $this->assertSame(['Todos los días'], $bna->validDays);

        $this->assertSame(4, Wallet::count());
        $this->assertTrue(Wallet::where('name', 'Carrefour Banco')->exists());
        $this->assertTrue(Wallet::where('name', 'Banco Patagonia')->exists());
    }

    public function test_merchant_name_is_carrefour(): void
    {
        $this->assertSame('Carrefour', $this->scraperWithFixture()->merchantName());
    }
}
