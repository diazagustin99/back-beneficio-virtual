<?php

namespace Tests\Unit\Actions;

use App\Actions\Scraping\ResolveWalletFromBankNameAction;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ResolveWalletFromBankNameActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_creates_a_wallet_on_first_call(): void
    {
        $wallet = app(ResolveWalletFromBankNameAction::class)->handle('Banco Comafi');

        $this->assertNotNull($wallet);
        $this->assertModelExists($wallet);
        $this->assertSame('Banco Comafi', $wallet->name);
        $this->assertSame('banco-comafi', $wallet->slug);
        $this->assertTrue($wallet->is_active);
        $this->assertSame(1, Wallet::count());
    }

    public function test_returns_the_existing_wallet_on_a_repeat_call(): void
    {
        $action = app(ResolveWalletFromBankNameAction::class);

        $first = $action->handle('Banco Comafi');
        $second = $action->handle('Banco Comafi');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Wallet::count());
    }

    public function test_a_name_that_only_differs_by_accents_spacing_or_case_resolves_to_the_existing_wallet(): void
    {
        $action = app(ResolveWalletFromBankNameAction::class);

        $first = $action->handle('Banco Comafi');
        $second = $action->handle('BANCO COMAFI');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Wallet::count());
    }

    public function test_a_known_alias_resolves_to_the_canonical_wallet_instead_of_a_duplicate(): void
    {
        config(['bank_wallet_aliases.aliases.bancodecomafi' => 'Comafi']);
        $canonical = Wallet::factory()->create(['name' => 'Comafi']);

        $resolved = app(ResolveWalletFromBankNameAction::class)->handle('Banco de Comafi');

        $this->assertSame($canonical->id, $resolved->id);
        $this->assertSame(1, Wallet::count());
    }

    public function test_renames_a_known_alias_when_the_canonical_wallet_does_not_exist_yet(): void
    {
        config(['bank_wallet_aliases.aliases.bancodecomafi' => 'Comafi']);

        $wallet = app(ResolveWalletFromBankNameAction::class)->handle('Banco de Comafi');

        $this->assertSame('Comafi', $wallet->name);
        $this->assertSame(1, Wallet::count());
    }

    public function test_the_alias_and_the_canonical_name_both_resolve_to_the_same_row(): void
    {
        config(['bank_wallet_aliases.aliases.bancodecomafi' => 'Comafi']);
        Wallet::factory()->create(['name' => 'Comafi']);
        $action = app(ResolveWalletFromBankNameAction::class);

        $viaCanonical = $action->handle('Comafi');
        $viaAlias = $action->handle('Banco de Comafi');

        $this->assertSame($viaCanonical->id, $viaAlias->id);
        $this->assertSame(1, Wallet::count());
    }

    /**
     * Regression test for a real incident: Cencosud's own "banks" feed lists
     * card networks ("Visa y Master", "Amex"), a government installment
     * program ("Plan Ahora 3") and other non-bank noise right alongside real
     * banks — creating a wallet for any of these would be exactly the
     * garbage the "verify before creating" discipline in this project
     * exists to avoid.
     */
    public function test_a_known_non_bank_label_resolves_to_null_instead_of_creating_a_wallet(): void
    {
        $wallet = app(ResolveWalletFromBankNameAction::class)->handle('Visa y Master');

        $this->assertNull($wallet);
        $this->assertSame(0, Wallet::count());
    }

    /**
     * Real config entries, not faked, so this fails if
     * config/bank_wallet_aliases.php regresses — mirrors the real
     * supermarket scrapers' own canonical bank names.
     */
    public function test_real_bank_aliases_resolve_to_their_real_existing_wallets(): void
    {
        $galicia = Wallet::factory()->create(['name' => 'Banco Galicia', 'slug' => 'galicia']);
        $bna = Wallet::factory()->create(['name' => 'Banco Nación', 'slug' => 'bna']);
        $santander = Wallet::factory()->create(['name' => 'Santander Río', 'slug' => 'santander']);
        $cuentaDni = Wallet::factory()->create(['name' => 'Cuenta DNI', 'slug' => 'cuenta_dni']);
        $action = app(ResolveWalletFromBankNameAction::class);

        $this->assertSame($galicia->id, $action->handle('Banco Galicia')->id);
        $this->assertSame($galicia->id, $action->handle('Galicia Modo')->id);
        $this->assertSame($bna->id, $action->handle('Nacion')->id);
        $this->assertSame($santander->id, $action->handle('Santander')->id);
        // La Anónima's own bank tile calls it "Banco DNI", but its own
        // legal text says "Con cuenta DNI..." — same real product.
        $this->assertSame($cuentaDni->id, $action->handle('Banco DNI')->id);
        $this->assertSame(4, Wallet::count());
    }

    /**
     * Regression for a real incident: La Anónima's own bank tile is
     * literally labeled "Banco Mastercard" — its own legal text just says
     * "Tarjetas de crédito Mastercard...", the card network, not a real
     * bank of its own.
     */
    public function test_banco_mastercard_resolves_to_null_instead_of_creating_a_wallet(): void
    {
        $wallet = app(ResolveWalletFromBankNameAction::class)->handle('Banco Mastercard');

        $this->assertNull($wallet);
        $this->assertSame(0, Wallet::count());
    }
}
