<?php

namespace Tests\Unit\Actions;

use App\Actions\PromotionCategories\MergeDuplicateCategoriesAction;
use App\Models\Promotion;
use App\Models\PromotionCategory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class MergeDuplicateCategoriesActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_moves_promotions_from_the_variant_to_the_canonical_category_and_deletes_the_variant(): void
    {
        config(['category_aliases' => ['transportes' => 'Transporte']]);

        $variant = PromotionCategory::factory()->create(['name' => 'Transportes', 'slug' => 'transportes']);
        Promotion::factory()->count(3)->create(['promotion_category_id' => $variant->id]);

        $merges = app(MergeDuplicateCategoriesAction::class)->handle();

        $this->assertCount(1, $merges);
        $this->assertSame(3, $merges[0]['promotions_moved']);

        $this->assertModelMissing($variant);
        $canonical = PromotionCategory::where('slug', 'transporte')->sole();
        $this->assertSame(3, Promotion::where('promotion_category_id', $canonical->id)->count());
    }

    public function test_merges_into_an_existing_canonical_category_instead_of_creating_a_new_one(): void
    {
        config(['category_aliases' => ['transportes' => 'Transporte']]);

        $canonical = PromotionCategory::factory()->create(['name' => 'Transporte', 'slug' => 'transporte']);
        $variant = PromotionCategory::factory()->create(['name' => 'Transportes', 'slug' => 'transportes']);
        Promotion::factory()->create(['promotion_category_id' => $variant->id]);
        Promotion::factory()->create(['promotion_category_id' => $canonical->id]);

        app(MergeDuplicateCategoriesAction::class)->handle();

        $this->assertSame(1, PromotionCategory::count());
        $this->assertSame(2, Promotion::where('promotion_category_id', $canonical->id)->count());
    }

    public function test_skips_an_alias_whose_variant_does_not_exist_in_the_database(): void
    {
        config(['category_aliases' => ['transportes' => 'Transporte']]);

        $merges = app(MergeDuplicateCategoriesAction::class)->handle();

        $this->assertSame([], $merges);
        $this->assertSame(0, PromotionCategory::count());
    }

    public function test_running_it_twice_is_a_no_op_the_second_time(): void
    {
        config(['category_aliases' => ['transportes' => 'Transporte']]);

        PromotionCategory::factory()->create(['name' => 'Transportes', 'slug' => 'transportes']);
        $action = app(MergeDuplicateCategoriesAction::class);

        $first = $action->handle();
        $second = $action->handle();

        $this->assertCount(1, $first);
        $this->assertSame([], $second);
        $this->assertSame(1, PromotionCategory::count());
    }
}
