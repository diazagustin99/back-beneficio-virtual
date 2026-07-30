<?php

namespace App\Actions\Scraping;

use App\Models\PromotionCategory;
use Illuminate\Support\Str;

class ResolvePromotionCategoryAction
{
    public function handle(?string $name): ?PromotionCategory
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $slug = Str::slug($name);

        return PromotionCategory::createOrFirst(['slug' => $slug], ['name' => trim($name), 'slug' => $slug]);
    }
}
