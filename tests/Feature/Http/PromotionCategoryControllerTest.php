<?php

namespace Tests\Feature\Http;

use App\Models\PromotionCategory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PromotionCategoryControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_index_lists_all_categories(): void
    {
        PromotionCategory::factory()->count(3)->create();

        $this->getJson('/api/v1/promotion-categories')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_show_returns_a_single_category(): void
    {
        $category = PromotionCategory::factory()->create();

        $this->getJson("/api/v1/promotion-categories/{$category->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $category->id);
    }

    public function test_show_returns_404_for_a_missing_category(): void
    {
        $this->getJson('/api/v1/promotion-categories/999')
            ->assertNotFound();
    }
}
