<?php

namespace App\Actions\Merchants;

use App\Models\Merchant;
use App\Services\Scraping\MerchantWordMatcher;
use Illuminate\Support\Facades\DB;

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
     * Safe to run more than once — once there's nothing left to merge, both
     * passes are simply no-ops.
     *
     * @return list<array{variant: string, canonical: string, promotions_moved: int}>
     */
    public function handle(): array
    {
        return DB::transaction(fn () => [
            ...$this->mergeExactDuplicates(),
            ...$this->mergeSentenceLikeNames(),
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
