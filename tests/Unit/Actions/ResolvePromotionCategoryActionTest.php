<?php

namespace Tests\Unit\Actions;

use App\Actions\Scraping\ResolvePromotionCategoryAction;
use App\Models\PromotionCategory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ResolvePromotionCategoryActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_creates_a_category_on_first_call(): void
    {
        $category = app(ResolvePromotionCategoryAction::class)->handle('Supermercados');

        $this->assertNotNull($category);
        $this->assertModelExists($category);
        $this->assertSame('supermercados', $category->slug);
    }

    public function test_returns_the_existing_category_on_a_repeat_call(): void
    {
        $action = app(ResolvePromotionCategoryAction::class);

        $first = $action->handle('Supermercados');
        $second = $action->handle('supermercados');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PromotionCategory::count());
    }

    public function test_null_name_returns_null_without_creating_a_row(): void
    {
        $category = app(ResolvePromotionCategoryAction::class)->handle(null);

        $this->assertNull($category);
        $this->assertSame(0, PromotionCategory::count());
    }

    public function test_a_known_variant_resolves_to_the_canonical_category_instead_of_a_duplicate(): void
    {
        config(['category_aliases.transportes' => 'Transporte']);

        $category = app(ResolvePromotionCategoryAction::class)->handle('Transportes');

        $this->assertSame('Transporte', $category->name);
        $this->assertSame('transporte', $category->slug);
        $this->assertSame(1, PromotionCategory::count());
    }

    public function test_the_variant_and_the_canonical_name_both_resolve_to_the_same_row(): void
    {
        config(['category_aliases.transportes' => 'Transporte']);
        $action = app(ResolvePromotionCategoryAction::class);

        $viaVariant = $action->handle('Transportes');
        $viaCanonical = $action->handle('Transporte');

        $this->assertSame($viaCanonical->id, $viaVariant->id);
        $this->assertSame(1, PromotionCategory::count());
    }
}
