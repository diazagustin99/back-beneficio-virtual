<?php

namespace Tests\Feature\Actions;

use App\Actions\Scraping\DeactivatePromotionsNotInRunAction;
use App\Models\Promotion;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class DeactivatePromotionsNotInRunActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_deactivates_active_promotions_not_present_in_the_seen_set(): void
    {
        $wallet = Wallet::factory()->create();
        $seen = Promotion::factory()->for($wallet)->create();
        $unseen = Promotion::factory()->for($wallet)->create();

        $count = app(DeactivatePromotionsNotInRunAction::class)
            ->handle($wallet, new Collection([$seen->id]));

        $this->assertSame(1, $count);
        $this->assertTrue($seen->fresh()->is_active);
        $this->assertFalse($unseen->fresh()->is_active);
        $this->assertNotNull($unseen->fresh()->deactivated_at);
    }

    public function test_already_inactive_promotions_are_not_recounted(): void
    {
        $wallet = Wallet::factory()->create();
        Promotion::factory()->for($wallet)->inactive()->create();

        $count = app(DeactivatePromotionsNotInRunAction::class)
            ->handle($wallet, new Collection);

        $this->assertSame(0, $count);
    }

    public function test_does_not_deactivate_another_wallets_promotions(): void
    {
        $walletA = Wallet::factory()->create();
        $walletB = Wallet::factory()->create();
        $promotionB = Promotion::factory()->for($walletB)->create();

        app(DeactivatePromotionsNotInRunAction::class)->handle($walletA, new Collection);

        $this->assertTrue($promotionB->fresh()->is_active);
    }
}
