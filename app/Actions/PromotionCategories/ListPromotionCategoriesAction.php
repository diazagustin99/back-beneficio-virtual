<?php

namespace App\Actions\PromotionCategories;

use App\Models\PromotionCategory;
use Illuminate\Database\Eloquent\Collection;

class ListPromotionCategoriesAction
{
    /**
     * @return Collection<int, PromotionCategory>
     */
    public function handle(): Collection
    {
        return PromotionCategory::query()->orderBy('name')->get();
    }
}
