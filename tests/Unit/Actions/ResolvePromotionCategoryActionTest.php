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
}
