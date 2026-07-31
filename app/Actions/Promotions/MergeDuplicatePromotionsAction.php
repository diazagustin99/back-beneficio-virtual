<?php

namespace App\Actions\Promotions;

use App\Models\Promotion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Some sources register the same real-world merchant under more than one
 * internal "brand" record, each running its own promotion — confirmed live
 * on BNA: Shell alone had 4 promotion rows, some offering the exact same
 * 20% cashback up to $10.000 with overlapping validity windows. A
 * source-side quirk, not a scraper bug (every row has a distinct, real
 * `external_id`) and not a merchant-name duplicate (they already share one
 * `merchant_id`) — so this collapses them by *offer*, not by *name*.
 *
 * A first version of this matched only on the numeric terms (discount %,
 * cashback %, cap, installments) plus category and overlapping dates — and
 * a real run immediately proved that unsafe: MODO runs the same "12 cuotas
 * en Frávega" template per issuing bank, each a separate promotion row with
 * every numeric field left `null` (nothing to extract a % from) and
 * overlapping dates, but a `description` that names a *different, mutually
 * exclusive bank* per row. Matching only on the numbers merged three
 * genuinely different, non-substitutable offers into one. `description`,
 * `terms`, and `valid_days` are exact-matched now specifically because
 * that's where a source spells out this kind of eligibility difference in
 * free text instead of a structured field — the fix isn't "match on more
 * things" in general, it's "match on the fields eligibility differences
 * actually show up in."
 *
 * Every field below must be identical (an offer's own wallet, merchant,
 * category, discount/cashback/fixed amount, cap, installments, description,
 * terms, and valid days) before two rows are even considered — and even
 * then, only if their validity windows overlap too. Differing on any single
 * one keeps them apart, even for the same merchant: distinct offers should
 * never disappear just because a coarser match would have caught them too
 * (the lesson from the two merchant-merge incidents in
 * `plans/0015-deduplicacion-comercios.md`, now repeated once here for
 * promotions before shipping).
 */
class MergeDuplicatePromotionsAction
{
    /**
     * Safe to run more than once — a re-scrape recreates the rows this
     * merges away (their `external_id`s still exist at the source), so
     * re-running after every scrape is the expected workflow, same as
     * `merchants:merge-duplicates`.
     *
     * @return list<array{wallet: string, merchant: string, survivor_id: int, merged_ids: list<int>}>
     */
    public function handle(): array
    {
        return DB::transaction(function () {
            $merges = [];

            $groups = Promotion::query()
                ->with(['paymentMethods', 'merchant:id,name', 'wallet:id,slug'])
                ->get()
                ->groupBy(fn (Promotion $promotion) => $this->offerKey($promotion))
                ->filter(fn (Collection $group) => $group->count() > 1);

            foreach ($groups as $group) {
                foreach ($this->clusterByOverlappingDates($group) as $cluster) {
                    if ($cluster->count() > 1) {
                        $merges[] = $this->mergeCluster($cluster);
                    }
                }
            }

            return $merges;
        });
    }

    private function offerKey(Promotion $promotion): string
    {
        return implode('|', [
            $promotion->wallet_id,
            $promotion->merchant_id,
            $promotion->promotion_category_id ?? 'none',
            $promotion->discount_percentage ?? 'none',
            $promotion->cashback_percentage ?? 'none',
            $promotion->fixed_amount ?? 'none',
            $promotion->reimbursement_cap ?? 'none',
            $promotion->installments ?? 'none',
            $this->normalizeText($promotion->description),
            $this->normalizeText($promotion->terms),
            implode(',', $promotion->valid_days),
        ]);
    }

    private function normalizeText(?string $value): string
    {
        return $value === null ? '' : trim($value);
    }

    /**
     * @param  Collection<int, Promotion>  $group
     * @return list<Collection<int, Promotion>>
     */
    private function clusterByOverlappingDates(Collection $group): array
    {
        $remaining = $group->values()->all();
        $clusters = [];

        while ($remaining !== []) {
            $cluster = [array_shift($remaining)];

            do {
                $absorbedAny = false;

                foreach ($remaining as $index => $candidate) {
                    foreach ($cluster as $member) {
                        if ($this->datesOverlap($member, $candidate)) {
                            $cluster[] = $candidate;
                            unset($remaining[$index]);
                            $absorbedAny = true;
                            break;
                        }
                    }
                }

                $remaining = array_values($remaining);
            } while ($absorbedAny);

            $clusters[] = collect($cluster);
        }

        return $clusters;
    }

    /**
     * A null `starts_at` means "always been valid" and a null `ends_at`
     * means "no known expiry" — either acts as an open bound.
     */
    private function datesOverlap(Promotion $a, Promotion $b): bool
    {
        if ($a->starts_at !== null && $b->ends_at !== null && $a->starts_at->gt($b->ends_at)) {
            return false;
        }

        if ($b->starts_at !== null && $a->ends_at !== null && $b->starts_at->gt($a->ends_at)) {
            return false;
        }

        return true;
    }

    /**
     * @param  Collection<int, Promotion>  $cluster
     * @return array{wallet: string, merchant: string, survivor_id: int, merged_ids: list<int>}
     */
    private function mergeCluster(Collection $cluster): array
    {
        $survivor = $cluster->sort(fn (Promotion $a, Promotion $b) => $this->survivorScore($b) <=> $this->survivorScore($a)
                ?: $a->id <=> $b->id
        )->first();

        $losers = $cluster->reject(fn (Promotion $promotion) => $promotion->id === $survivor->id);

        $this->widenValidityWindow($survivor, $cluster);
        $this->mergePaymentMethods($survivor, $losers);

        $mergedIds = $losers->pluck('id')->all();

        foreach ($losers as $loser) {
            $loser->delete();
        }

        return [
            'wallet' => $survivor->wallet->slug,
            'merchant' => $survivor->merchant->name,
            'survivor_id' => $survivor->id,
            'merged_ids' => $mergedIds,
        ];
    }

    /**
     * `terms`, `description`, and `valid_days` are already identical across
     * a cluster (they're part of the match key) — the only thing left to
     * prefer between otherwise-identical rows is a real, followable url
     * over a broken placeholder (BNA's own site has been observed to use
     * `"###"` for one).
     */
    private function survivorScore(Promotion $promotion): int
    {
        return $this->hasUsableUrl($promotion->url) ? 1 : 0;
    }

    private function hasUsableUrl(?string $url): bool
    {
        return $url !== null && preg_match('#^https?://#i', $url) === 1;
    }

    /**
     * Extends the survivor to cover the union of the cluster's validity
     * windows, so a merged-away row's wider range isn't lost.
     *
     * @param  Collection<int, Promotion>  $cluster
     */
    private function widenValidityWindow(Promotion $survivor, Collection $cluster): void
    {
        $updates = [];

        $earliestStart = $cluster->pluck('starts_at')->filter()->sort()->first();

        if ($earliestStart !== null && ($survivor->starts_at === null || $earliestStart->lt($survivor->starts_at))) {
            $updates['starts_at'] = $earliestStart;
        }

        if ($survivor->ends_at !== null) {
            $hasOpenEnd = $cluster->contains(fn (Promotion $promotion) => $promotion->ends_at === null);
            $latestEnd = $hasOpenEnd ? null : $cluster->pluck('ends_at')->sort()->last();

            if ($hasOpenEnd || ($latestEnd !== null && $latestEnd->gt($survivor->ends_at))) {
                $updates['ends_at'] = $latestEnd;
            }
        }

        if ($updates !== []) {
            $survivor->update($updates);
        }
    }

    /**
     * @param  Collection<int, Promotion>  $losers
     */
    private function mergePaymentMethods(Promotion $survivor, Collection $losers): void
    {
        $existing = $survivor->paymentMethods->pluck('name')->all();

        foreach ($losers as $loser) {
            foreach ($loser->paymentMethods as $method) {
                if (! in_array($method->name, $existing, true)) {
                    $survivor->paymentMethods()->create(['name' => $method->name]);
                    $existing[] = $method->name;
                }
            }
        }
    }
}
