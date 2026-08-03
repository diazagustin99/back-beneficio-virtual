<?php

namespace App\Actions\Merchants;

use App\Models\Merchant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ListMerchantsAction
{
    /**
     * `with_discounts` and `promotion_category_id` are both opt-in: the
     * onboarding merchant picker and this same endpoint's plain listing
     * behavior stay exactly as they were (merchants with zero promotions
     * still show up) unless the caller explicitly asks for the "has active
     * discounts" view used by the merchant-first browsing screens.
     *
     * @param  array{search?: string, with_discounts?: bool, promotion_category_id?: int, merchant_ids?: list<int>, with_logo_first?: bool, per_page?: int}  $filters
     */
    public function handle(array $filters = []): LengthAwarePaginator
    {
        $withDiscounts = (bool) ($filters['with_discounts'] ?? false);
        $withLogoFirst = (bool) ($filters['with_logo_first'] ?? false);

        return Merchant::query()
            ->when(
                filled($filters['search'] ?? null),
                fn (Builder $query) => $query->where('name', 'like', '%'.$filters['search'].'%'),
            )
            ->when(
                filled($filters['merchant_ids'] ?? null),
                fn (Builder $query) => $query->whereIn('id', $filters['merchant_ids']),
            )
            ->when($withDiscounts, fn (Builder $query) => $query->whereHas(
                'promotions',
                fn (Builder $promotions) => $promotions->active(),
            ))
            ->when(
                filled($filters['promotion_category_id'] ?? null),
                fn (Builder $query) => $query->whereHas(
                    'promotions',
                    fn (Builder $promotions) => $promotions->active()
                        ->where('promotion_category_id', $filters['promotion_category_id']),
                ),
            )
            ->when($withDiscounts, fn (Builder $query) => $query
                // withCount()/with() constraint closures are called from more
                // than one internal code path and are handed a `HasMany` in
                // some and a plain `Builder` in others — the union type
                // accepts whichever this Laravel version passes.
                ->withCount(['promotions' => fn (HasMany|Builder $promotions) => $promotions->active()])
                ->with(['promotions' => fn (HasMany|Builder $promotions) => $promotions->active()
                    ->with('wallet')
                    ->select(['id', 'merchant_id', 'wallet_id'])]))
            // `logo_url IS NULL` evaluates to 0 for merchants that have one
            // and 1 for those that don't, so ascending order puts the ones
            // with a real logo first — a plain `orderBy('logo_url')` would
            // instead sort by the URL string itself.
            ->when($withLogoFirst, fn (Builder $query) => $query->orderByRaw('logo_url IS NULL'))
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 15);
    }
}
