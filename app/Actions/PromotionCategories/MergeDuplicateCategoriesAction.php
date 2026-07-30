<?php

namespace App\Actions\PromotionCategories;

use App\Models\PromotionCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MergeDuplicateCategoriesAction
{
    /**
     * Applies `config('category_aliases')` to categories that already exist
     * in the database: every promotion pointing at a variant category is
     * reassigned to the canonical one, then the now-empty variant row is
     * deleted. Safe to run more than once — a variant with no matching row
     * is simply skipped.
     *
     * @return list<array{variant: string, canonical: string, promotions_moved: int}>
     */
    public function handle(): array
    {
        return DB::transaction(function () {
            $merges = [];

            foreach (config('category_aliases', []) as $variantSlug => $canonicalName) {
                $variant = PromotionCategory::where('slug', $variantSlug)->first();

                if ($variant === null) {
                    continue;
                }

                $canonicalSlug = Str::slug($canonicalName);

                $canonical = PromotionCategory::createOrFirst(
                    ['slug' => $canonicalSlug],
                    ['name' => $canonicalName, 'slug' => $canonicalSlug],
                );

                if ($canonical->id === $variant->id) {
                    continue;
                }

                $promotionsMoved = $variant->promotions()->update(['promotion_category_id' => $canonical->id]);

                $variant->delete();

                $merges[] = [
                    'variant' => $variant->name,
                    'canonical' => $canonical->name,
                    'promotions_moved' => $promotionsMoved,
                ];
            }

            return $merges;
        });
    }
}
