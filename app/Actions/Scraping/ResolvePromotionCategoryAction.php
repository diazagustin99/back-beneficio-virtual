<?php

namespace App\Actions\Scraping;

use App\Models\PromotionCategory;
use App\Services\Scraping\CategoryWordMatcher;
use Illuminate\Support\Str;

class ResolvePromotionCategoryAction
{
    public function __construct(
        private readonly CategoryWordMatcher $wordMatcher,
    ) {}

    /**
     * @var string[]
     */
    private const array LOWERCASE_CONNECTORS = ['y', 'de', 'del', 'la', 'las', 'el', 'los', 'en', 'a', 'con', 'para', 'por'];

    public function handle(?string $name): ?PromotionCategory
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $trimmed = trim($name);
        $slug = Str::slug($trimmed);

        // A variant slug (e.g. "transportes") resolves to the canonical
        // category's real name (e.g. "Transporte") instead of creating a
        // near-duplicate — see config/category_aliases.php.
        $canonicalName = config("category_aliases.{$slug}");

        if ($canonicalName !== null) {
            $canonicalSlug = Str::slug($canonicalName);

            return PromotionCategory::createOrFirst(
                ['slug' => $canonicalSlug],
                ['name' => $canonicalName, 'slug' => $canonicalSlug],
            );
        }

        $existing = PromotionCategory::where('slug', $slug)->first();

        if ($existing !== null) {
            return $existing;
        }

        // Last resort before creating a brand-new category: does an
        // existing one already, unambiguously, cover this word — e.g.
        // "Autos" already covered by "Autos y motos"? See
        // CategoryWordMatcher and plans/0025-categorias-duplicadas.md.
        $matched = $this->wordMatcher->findSingleMatch($trimmed);

        if ($matched !== null) {
            return $matched;
        }

        $name = $this->normalizeShoutingName($trimmed);

        return PromotionCategory::createOrFirst(['slug' => $slug], ['name' => $name, 'slug' => $slug]);
    }

    /**
     * ICBC's `rubro` field (and potentially other future sources) always
     * arrives fully upper-case (e.g. "VINOS Y BODEGAS") — every other
     * source already sends a properly-cased name, so this only touches a
     * string that is entirely upper-case, converting it to Spanish title
     * case instead of persisting it shouting. Doesn't retroactively fix a
     * row that already exists with the ugly casing — `createOrFirst` never
     * updates an existing row's name, only new ones benefit.
     */
    private function normalizeShoutingName(string $name): string
    {
        if ($name !== mb_strtoupper($name, 'UTF-8')) {
            return $name;
        }

        $words = explode(' ', mb_strtolower($name, 'UTF-8'));

        $titled = array_map(function (string $word, int $index) {
            if ($word === '') {
                return $word;
            }

            if ($index > 0 && in_array($word, self::LOWERCASE_CONNECTORS, true)) {
                return $word;
            }

            return mb_convert_case($word, MB_CASE_TITLE, 'UTF-8');
        }, $words, array_keys($words));

        return implode(' ', $titled);
    }
}
