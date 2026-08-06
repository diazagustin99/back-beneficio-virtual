<?php

namespace App\Actions\Merchants;

use App\Models\Merchant;
use App\Services\Scraping\MerchantWordMatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Unlike categories (a handful of known values, aliased by hand), merchants
 * number in the thousands — duplicates here are found algorithmically:
 * first by exact normalized name, then by spotting an existing merchant's
 * name hiding inside a longer, sentence-like one (see `MerchantWordMatcher`).
 */
class MergeDuplicateMerchantsAction
{
    public function __construct(
        private readonly MerchantWordMatcher $wordMatcher,
    ) {}

    /**
     * Safe to run more than once — once there's nothing left to merge, all
     * five passes are simply no-ops.
     *
     * @return list<array{variant: string, canonical: string, promotions_moved: int}>
     */
    public function handle(): array
    {
        return DB::transaction(fn () => [
            ...$this->mergeExactDuplicates(),
            ...$this->mergeSentenceLikeNames(),
            ...$this->mergeAliasedNames(),
            ...$this->mergeSupervielleSuffixNames(),
            ...$this->mergeGenericCoreNameDuplicates(),
        ]);
    }

    /**
     * Groups every merchant by `normalized_name` (case/accent/space/
     * punctuation-insensitive) and, for each group with more than one row,
     * keeps the one with the most promotions (ties broken by lowest id).
     *
     * @return list<array{variant: string, canonical: string, promotions_moved: int}>
     */
    private function mergeExactDuplicates(): array
    {
        $merges = [];

        $groups = Merchant::query()
            ->withCount('promotions')
            ->get(['id', 'name', 'normalized_name', 'logo_url'])
            ->groupBy('normalized_name')
            ->filter(fn ($group) => $group->count() > 1);

        foreach ($groups as $group) {
            $canonical = $group->sortBy([['promotions_count', 'desc'], ['id', 'asc']])->first();

            foreach ($group as $variant) {
                if ($variant->id !== $canonical->id) {
                    $merges[] = $this->mergeInto($variant, $canonical);
                }
            }
        }

        return $merges;
    }

    /**
     * For every remaining merchant that mentions one of `MerchantWordMatcher`'s
     * known chain names, checks whether it has exactly one *other* existing
     * merchant's name hiding inside it — e.g. "Ahorrá en Carrefour" matching
     * the already-known "Carrefour".
     *
     * @return list<array{variant: string, canonical: string, promotions_moved: int}>
     */
    private function mergeSentenceLikeNames(): array
    {
        $merges = [];
        $mergedAwayIds = [];

        $candidates = Merchant::query()
            ->get(['id', 'name', 'logo_url'])
            ->filter(fn ($merchant) => $this->wordMatcher->mentionsAKnownChain($merchant->name));

        foreach ($candidates as $variant) {
            if (in_array($variant->id, $mergedAwayIds, true)) {
                continue;
            }

            $canonical = $this->wordMatcher->findSingleMatch($variant->name, excludeMerchantId: $variant->id);

            if ($canonical === null || in_array($canonical->id, $mergedAwayIds, true)) {
                continue;
            }

            $merges[] = $this->mergeInto($variant, $canonical);
            $mergedAwayIds[] = $variant->id;
        }

        return $merges;
    }

    /**
     * Hand-verified name variants (see config/merchant_aliases.php) — a
     * variant merges into its canonical merchant if one already exists, or
     * is simply renamed to the canonical name otherwise (nothing to merge
     * into yet).
     *
     * @return list<array{variant: string, canonical: string, promotions_moved: int}>
     */
    private function mergeAliasedNames(): array
    {
        $aliases = config('merchant_aliases');
        $merges = [];

        $candidates = Merchant::query()->get(['id', 'name', 'normalized_name', 'logo_url']);

        foreach ($candidates as $variant) {
            $canonicalName = $aliases[$variant->normalized_name] ?? null;

            if ($canonicalName === null || Merchant::normalize($canonicalName) === $variant->normalized_name) {
                continue;
            }

            $canonical = Merchant::query()
                ->where('normalized_name', Merchant::normalize($canonicalName))
                ->where('id', '!=', $variant->id)
                ->first();

            if ($canonical === null) {
                $variant->update(['name' => $canonicalName]);

                continue;
            }

            $merges[] = $this->mergeInto($variant, $canonical);
        }

        return $merges;
    }

    /**
     * Retroactive cleanup for `SupervielleScraper`'s own name-cleaning fixes
     * (`Merchant::stripModoSuffix()`, `stripVisaSuffix()`, and
     * `stripSegmentQualifierSuffix()`) — a scrape that ran before those
     * fixes existed already created merchants like "AG Piazze con MODO",
     * "COTO con Visa débito NFC" and "Disco - Jubilados" for real businesses
     * "AG Piazze", "Coto" and "Disco". Same merge-or-rename behaviour as
     * `mergeAliasedNames()` above, just driven by a structural rule instead
     * of a hand-verified name list — renaming a variant here can itself
     * create the canonical merchant a later variant in the same pass then
     * merges into, since each lookup re-queries the database rather than
     * relying on a stale snapshot.
     *
     * @return list<array{variant: string, canonical: string, promotions_moved: int}>
     */
    private function mergeSupervielleSuffixNames(): array
    {
        $merges = [];

        $candidates = Merchant::query()->get(['id', 'name', 'logo_url']);

        foreach ($candidates as $variant) {
            $cleanName = Merchant::stripSegmentQualifierSuffix(
                Merchant::stripVisaSuffix(Merchant::stripModoSuffix($variant->name))
            );

            if ($cleanName === $variant->name) {
                continue;
            }

            $canonical = Merchant::query()
                ->where('normalized_name', Merchant::normalize($cleanName))
                ->where('id', '!=', $variant->id)
                ->first();

            if ($canonical === null) {
                $variant->update(['name' => $cleanName]);

                continue;
            }

            $merges[] = $this->mergeInto($variant, $canonical);
        }

        return $merges;
    }

    /**
     * Words that never identify a specific business on their own — generic
     * business-type nouns ("Supermercado(s)", "Super", "Mercado(s)"),
     * Spanish articles/conjunctions, legal-form suffixes ("SA", "SRL"),
     * branch markers ("Grupo") and roman-numeral branch numbers. Stripped
     * before comparing two names' remaining "core" words — see
     * `mergeGenericCoreNameDuplicates()`.
     *
     * @var list<string>
     */
    private const array GENERIC_CORE_WORDS = [
        'supermercados', 'supermercado', 'super', 'mercado', 'mercados',
        'sa', 'srl', 'grupo', 'group',
        'de', 'del', 'la', 'el', 'los', 'las', 'y', 'e', 'al',
        'ii', 'iii', 'iv', 'v',
    ];

    /**
     * The remaining duplicates after the passes above are almost always the
     * same small chain named with a different word order, a generic
     * business-type word added or dropped, an "El"/"La" article, a legal
     * suffix, or a branch marker — e.g. "Toledo" / "Supermercado Toledo" /
     * "Supermercados Toledo". Merchants number in the thousands (unlike the
     * handful of hand-verified aliases above), so — per this class's own
     * docblock — this has to be found algorithmically rather than listed by
     * hand: this pass groups merchants by their name with `GENERIC_CORE_WORDS`
     * stripped, and merges any group left with more than one distinct name.
     *
     * The risk `MerchantWordMatcher` already documented applies here too:
     * several ordinary Spanish words/phrases ("El Sol", "Beltrán", "Austral")
     * are independently used as the *entire* name by multiple, genuinely
     * unrelated small businesses. The guard: a group only merges if every
     * member that has any promotion shares at least one category with every
     * other member that has promotions — e.g. "El Sol" (Construcción) and
     * "Sol Supermercado" (Supermercados) share nothing and are left alone,
     * while "Toledo" and "Supermercado Toledo" (both Supermercados) merge.
     *
     * @return list<array{variant: string, canonical: string, promotions_moved: int}>
     */
    private function mergeGenericCoreNameDuplicates(): array
    {
        $merges = [];

        $merchants = Merchant::query()
            ->withCount('promotions')
            ->with('promotions.category')
            ->get(['id', 'name', 'normalized_name', 'logo_url']);

        $groups = $merchants
            ->groupBy(fn (Merchant $merchant) => $this->coreSignature($merchant->name))
            ->reject(fn ($group, $signature) => $signature === '');

        foreach ($groups as $group) {
            if ($group->pluck('normalized_name')->unique()->count() < 2) {
                continue;
            }

            if ($this->hasConflictingCategories($group)) {
                continue;
            }

            $canonical = $group->sortBy([['promotions_count', 'desc'], ['id', 'asc']])->first();

            foreach ($group as $variant) {
                if ($variant->id !== $canonical->id) {
                    $merges[] = $this->mergeInto($variant, $canonical);
                }
            }
        }

        return $merges;
    }

    /**
     * Lowercases, strips accents/punctuation and `GENERIC_CORE_WORDS`, then
     * sorts what's left — so word order and generic filler never affect
     * whether two names land in the same group. Returns '' (never grouped)
     * for a name that's entirely generic filler or purely numeric, since
     * neither identifies a specific business.
     */
    private function coreSignature(string $name): string
    {
        $tokens = preg_split('/[^a-zA-Z0-9]+/', Str::ascii($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $core = array_values(array_diff(array_map('mb_strtolower', $tokens), self::GENERIC_CORE_WORDS));
        sort($core);
        $signature = implode('-', $core);

        return preg_match('/^\d+(-\d+)*$/', $signature) === 1 ? '' : $signature;
    }

    /**
     * @param  Collection<int, Merchant>  $group
     */
    private function hasConflictingCategories(Collection $group): bool
    {
        $categorySets = $group
            ->map(fn (Merchant $merchant) => $merchant->promotions->pluck('category.name')->filter()->unique())
            ->filter(fn ($categories) => $categories->isNotEmpty())
            ->values();

        for ($i = 0; $i < $categorySets->count(); $i++) {
            for ($j = $i + 1; $j < $categorySets->count(); $j++) {
                if ($categorySets[$i]->intersect($categorySets[$j])->isEmpty()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{variant: string, canonical: string, promotions_moved: int}
     */
    private function mergeInto(Merchant $variant, Merchant $canonical): array
    {
        if ($canonical->logo_url === null && $variant->logo_url !== null) {
            $canonical->update(['logo_url' => $variant->logo_url]);
        }

        $promotionsMoved = $variant->promotions()->update(['merchant_id' => $canonical->id]);

        $variant->delete();

        return [
            'variant' => $variant->name,
            'canonical' => $canonical->name,
            'promotions_moved' => $promotionsMoved,
        ];
    }
}
