<?php

namespace App\Services\Scraping;

use App\Models\PromotionCategory;
use Illuminate\Support\Collection;

/**
 * Finds a single, unambiguous *broader* category that already covers a raw
 * incoming category name — e.g. "Autos" resolving to the already-existing
 * "Autos y motos" instead of creating a near-duplicate. Only consulted by
 * `ResolvePromotionCategoryAction` as the last resort, after both the
 * exact-name and `config/category_aliases.php` lookups miss — see
 * plans/0025-categorias-duplicadas.md.
 *
 * Deliberately excludes the compound categories that intentionally combine
 * two distinct rubros (see `config/category_aliases.php`'s own docblock,
 * e.g. "Jugueterías y Librerías") — a word from either half would otherwise
 * "match" a category that doesn't really describe it (confirmed live: a raw
 * "Jugueterias", plural, has no exact-name match of its own but its only
 * token *is* a token of "Jugueterías y Librerías" — matching there would be
 * wrong, "Jugueterías y Librerías" isn't a toy store). Same "only match
 * what's provably safe, refuse anything ambiguous" discipline
 * `MerchantWordMatcher` already established for comercios — see that
 * class's own docblock for the incident that taught this lesson.
 */
class CategoryWordMatcher
{
    /**
     * Categories explicitly documented as a deliberate combination of two
     * distinct rubros — never a valid match target here, no matter how
     * confident the word overlap looks.
     *
     * @var list<string>
     */
    private const array EXCLUDED_FROM_MATCHING = [
        'jugueterias-y-librerias',
        'turismo-y-entretenimiento',
    ];

    /**
     * Grammatical connectors — never carry meaning, always ignored.
     *
     * @var list<string>
     */
    private const array STOPWORDS = [
        'de', 'la', 'el', 'los', 'las', 'en', 'con', 'para', 'por', 'un', 'una',
        'y', 'del', 'al', 'a', 'o',
    ];

    /**
     * Only resolves when every significant word of `$name` is also present
     * in exactly one existing category's own name — zero or more than one
     * candidate both mean "don't guess", same as a plain Spanish word with
     * no known match at all.
     */
    public function findSingleMatch(string $name): ?PromotionCategory
    {
        $tokens = $this->tokenize($name);

        if ($tokens === []) {
            return null;
        }

        $candidates = PromotionCategory::query()
            ->whereNotIn('slug', self::EXCLUDED_FROM_MATCHING)
            ->get(['id', 'name'])
            ->filter(fn (PromotionCategory $category) => $this->containsEveryToken($category->name, $tokens));

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * @param  list<string>  $tokens
     */
    private function containsEveryToken(string $categoryName, array $tokens): bool
    {
        return array_diff($tokens, $this->tokenize($categoryName)) === [];
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $name): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $normalized = array_map(fn (string $word) => PromotionCategory::normalize($word), $words);

        return Collection::make($normalized)->unique()->diff(self::STOPWORDS)->values()->all();
    }
}
