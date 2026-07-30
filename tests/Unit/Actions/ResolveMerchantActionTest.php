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
}
