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

        // A variant slug (e.g. "transportes") resolves to the canonical
        // category's real name (e.g. "Transporte") instead of creating a
        // near-duplicate — see config/category_aliases.php.
        $canonicalName = config("category_aliases.{$slug}");
        $name = $canonicalName ?? trim($name);
        $slug = $canonicalName !== null ? Str::slug($canonicalName) : $slug;

        return PromotionCategory::createOrFirst(['slug' => $slug], ['name' => $name, 'slug' => $slug]);
    }
}
