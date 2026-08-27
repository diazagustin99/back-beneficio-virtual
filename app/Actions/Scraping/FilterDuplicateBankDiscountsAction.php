<?php

namespace App\Actions\Scraping;

use App\DTOs\PromotionDTO;
use App\Models\Merchant;
use App\Models\Promotion;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Builder;

/**
 * The user's own dedup rule for the merchant-scraping pipeline (see
 * `MerchantScraperInterface`): if a supermarket's own page names the same
 * merchant, wallet, day and discount as an already-active promotion — most
 * often one that bank's *own* scraper already created independently — treat
 * it as a duplicate and never create it. Deliberately narrower than
 * `MergeDuplicatePromotionsAction`'s own criteria (description/terms/valid
 * days match exactly): here the wallet is *already* part of the key, which
 * is what defeated the one real false-positive incident that action's own
 * docblock documents (`plans/0019-deduplicacion-promociones.md` — MODO
 * running the same numeric template per bank, no bank in the key at all).
 * If the same pattern ever turns up here, extend the key with
 * `installments`/`minimum_purchase`, not free-text — see the plan doc.
 *
 * Runs *before* `UpsertPromotionFromDtoAction` for every DTO a merchant
 * scraper yields, which is exactly why this pipeline is scheduled to run
 * only after every wallet has finished scraping for the day (see
 * `ScrapeSupermarketsCommand`) — a bank's own promotion has to already be in
 * the database for this check to find it.
 */
class FilterDuplicateBankDiscountsAction
{
    /**
     * @param  iterable<int, PromotionDTO>  $dtos
     * @return iterable<int, PromotionDTO>
     */
    public function handle(Merchant $merchant, iterable $dtos): iterable
    {
        $walletsBySlug = [];

        foreach ($dtos as $dto) {
            $wallet = $walletsBySlug[$dto->walletSlug] ??= Wallet::query()->where('slug', $dto->walletSlug)->first();

            // An unresolvable wallet isn't this Action's problem to solve —
            // let it through so SyncPromotionsFromScraperAction's own
            // failure handling reports it per-DTO, instead of silently
            // dropping a promotion that never even got a chance to fail loudly.
            if ($wallet === null || ! $this->alreadyExists($merchant, $wallet, $dto)) {
                yield $dto;
            }
        }
    }

    private function alreadyExists(Merchant $merchant, Wallet $wallet, PromotionDTO $dto): bool
    {
        return Promotion::query()
            ->where('merchant_id', $merchant->id)
            ->where('wallet_id', $wallet->id)
            ->where('is_active', true)
            ->where('discount_percentage', $this->nullIfZero($dto->discountPercentage))
            ->where('cashback_percentage', $this->nullIfZero($dto->cashbackPercentage))
            ->where('fixed_amount', $this->nullIfZero($dto->fixedAmount))
            ->where('installments', $dto->installments)
            ->where(fn (Builder $query) => $this->matchesValidDays($query, $dto->validDays))
            ->exists();
    }

    /**
     * Same "0 doesn't mean a real value" rule `UpsertPromotionFromDtoAction`
     * applies before saving — matched here too so a DTO whose discount will
     * be stored as `null` is compared against what's actually in the
     * database, not against a literal `0`.
     */
    private function nullIfZero(?float $value): ?float
    {
        return $value === 0.0 ? null : $value;
    }

    /**
     * Same "any overlap, and 'Todos los días' always matches" rule
     * `ListPromotionsAction::applyValidDays()` already uses for filtering.
     *
     * @param  Builder<Promotion>  $query
     * @param  string[]  $days
     * @return Builder<Promotion>
     */
    private function matchesValidDays(Builder $query, array $days): Builder
    {
        return $query->where(function (Builder $q) use ($days) {
            $q->whereJsonContains('valid_days', 'Todos los días');

            foreach ($days as $day) {
                $q->orWhereJsonContains('valid_days', $day);
            }
        });
    }
}
