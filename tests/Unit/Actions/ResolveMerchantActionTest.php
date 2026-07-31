<?php

namespace Tests\Unit\Actions;

use App\Actions\Scraping\ResolveMerchantAction;
use App\Models\Merchant;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ResolveMerchantActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_creates_a_merchant_on_first_call(): void
    {
        $merchant = app(ResolveMerchantAction::class)->handle('Carrefour');

        $this->assertModelExists($merchant);
        $this->assertSame('Carrefour', $merchant->name);
        $this->assertSame('carrefour', $merchant->slug);
        $this->assertSame(1, Merchant::count());
    }

    public function test_returns_the_existing_merchant_on_a_repeat_call(): void
    {
        $action = app(ResolveMerchantAction::class);

        $first = $action->handle('Carrefour');
        $second = $action->handle('carrefour');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Merchant::count());
    }

    public function test_stores_the_icon_url_when_creating_a_merchant(): void
    {
        $merchant = app(ResolveMerchantAction::class)->handle('Carrefour', 'https://example.com/carrefour.png');

        $this->assertSame('https://example.com/carrefour.png', $merchant->logo_url);
    }

    public function test_updates_the_icon_url_on_an_existing_merchant_when_a_new_one_is_provided(): void
    {
        $action = app(ResolveMerchantAction::class);

        $action->handle('Carrefour', 'https://example.com/old.png');
        $updated = $action->handle('Carrefour', 'https://example.com/new.png');

        $this->assertSame('https://example.com/new.png', $updated->fresh()->logo_url);
        $this->assertSame(1, Merchant::count());
    }

    public function test_keeps_the_existing_icon_url_when_none_is_provided_on_a_repeat_call(): void
    {
        $action = app(ResolveMerchantAction::class);

        $action->handle('Carrefour', 'https://example.com/logo.png');
        $again = $action->handle('Carrefour');

        $this->assertSame('https://example.com/logo.png', $again->fresh()->logo_url);
    }

    public function test_a_name_that_only_differs_by_accents_spacing_or_punctuation_resolves_to_the_existing_merchant(): void
    {
        $action = app(ResolveMerchantAction::class);

        $first = $action->handle('Mimo&co');
        $second = $action->handle('Mimo & Co.');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Merchant::count());
    }

    public function test_a_promotional_sentence_resolves_to_the_existing_merchant_named_inside_it(): void
    {
        $action = app(ResolveMerchantAction::class);
        $carrefour = $action->handle('Carrefour');

        $resolved = $action->handle('¡Especial Carrefour de los sábados!');

        $this->assertSame($carrefour->id, $resolved->id);
        $this->assertSame(1, Merchant::count());
    }

    public function test_a_sentence_matching_more_than_one_known_chain_creates_its_own_entry_instead_of_guessing(): void
    {
        $action = app(ResolveMerchantAction::class);
        $action->handle('Carrefour');
        $action->handle('Coto');

        $resolved = $action->handle('Comparamos precios: Carrefour vs Coto');

        $this->assertSame('Comparamos precios: Carrefour vs Coto', $resolved->name);
        $this->assertSame(3, Merchant::count());
    }

    /**
     * Regression test for a real incident: an earlier version matched any
     * word against the entire merchant catalog, and since the catalog
     * already had a junk merchant literally named "Repuestos" ("spare
     * parts") from unrelated scraping noise, it silently merged seven
     * unrelated auto parts shops together. Matching must only ever consider
     * the short, curated list of known chains — never an arbitrary existing
     * merchant name, however exactly it matches.
     */
    public function test_a_generic_word_that_happens_to_already_be_a_merchant_is_never_used_to_match(): void
    {
        $action = app(ResolveMerchantAction::class);
        $genericMerchant = $action->handle('Repuestos');

        $resolved = $action->handle('Repuestos Ford');

        $this->assertNotSame($genericMerchant->id, $resolved->id);
        $this->assertSame('Repuestos Ford', $resolved->name);
        $this->assertSame(2, Merchant::count());
    }

    /**
     * Regression test for a real incident: "Riadigos" is a pharmacy chain
     * with a stand inside a Carrefour hypermarket — a genuinely different
     * business — but its Naranja X listing is named "Riadigos Carrefour",
     * which used to silently resolve to the "Carrefour" supermarket merchant.
     * A name only resolves to a known chain when every other word in it is
     * recognized promotional filler; "Riadigos" isn't, so it must stay its
     * own merchant.
     */
    public function test_a_different_business_that_merely_mentions_a_known_chain_is_not_merged_into_it(): void
    {
        $action = app(ResolveMerchantAction::class);
        $carrefour = $action->handle('Carrefour');

        $resolved = $action->handle('Riadigos Carrefour');

        $this->assertNotSame($carrefour->id, $resolved->id);
        $this->assertSame('Riadigos Carrefour', $resolved->name);
        $this->assertSame(2, Merchant::count());
    }
}
