<?php

namespace Tests\Feature\Actions;

use App\Actions\Promotions\DeactivateExpiredPromotionsAction;
use App\Models\Promotion;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DeactivateExpiredPromotionsActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_deactivates_active_promotions_past_their_ends_at_date(): void
    {
        $expired = Promotion::factory()->create(['ends_at' => now()->subDay()]);

        $count = app(DeactivateExpiredPromotionsAction::class)->handle();

        $this->assertSame(1, $count);
        $this->assertFalse($expired->fresh()->is_active);
        $this->assertNotNull($expired->fresh()->deactivated_at);
    }

    public function test_does_not_touch_promotions_still_valid(): void
    {
        $stillValid = Promotion::factory()->create(['ends_at' => now()->addDay()]);

        $count = app(DeactivateExpiredPromotionsAction::class)->handle();

        $this->assertSame(0, $count);
        $this->assertTrue($stillValid->fresh()->is_active);
    }

    public function test_does_not_touch_promotions_without_an_end_date(): void
    {
        $noEndDate = Promotion::factory()->create(['ends_at' => null]);

        $count = app(DeactivateExpiredPromotionsAction::class)->handle();

        $this->assertSame(0, $count);
        $this->assertTrue($noEndDate->fresh()->is_active);
    }

    public function test_already_inactive_promotions_are_not_recounted(): void
    {
        Promotion::factory()->inactive()->create(['ends_at' => now()->subDay()]);

        $count = app(DeactivateExpiredPromotionsAction::class)->handle();

        $this->assertSame(0, $count);
    }
}
