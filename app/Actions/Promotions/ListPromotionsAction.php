<?php

namespace App\Actions\Promotions;

use App\Models\Promotion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ListPromotionsAction
{
    /**
     * @param  array{wallet?: list<string>, merchant_id?: list<int>, promotion_category_id?: list<int>, valid_days?: list<string>, is_active?: bool, valid_on?: string, search?: string, per_page?: int}  $filters
     */
    public function handle(array $filters = []): LengthAwarePaginator
    {
        return Promotion::query()
            ->with(['wallet', 'merchant', 'category', 'paymentMethods'])
            ->when(filled($filters['wallet'] ?? null), fn ($query) => $query->forWallets($filters['wallet']))
            ->when(filled($filters['merchant_id'] ?? null), fn ($query) => $query->whereIn('merchant_id', $filters['merchant_id']))
            ->when(filled($filters['promotion_category_id'] ?? null), fn ($query) => $query->whereIn('promotion_category_id', $filters['promotion_category_id']))
            ->when(filled($filters['valid_days'] ?? null), fn ($query) => $this->applyValidDays($query, $filters['valid_days']))
            ->when(array_key_exists('is_active', $filters), fn ($query) => $query->where('is_active', $filters['is_active']))
            ->when(filled($filters['valid_on'] ?? null), fn ($query) => $query->validOn(Carbon::parse($filters['valid_on'])))
            ->when(filled($filters['search'] ?? null), fn ($query) => $query->where('title', 'like', '%'.$filters['search'].'%'))
            ->latest('last_seen_at')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Matches a promotion valid on ANY of the given days. A promotion whose
     * source marks it valid "Todos los días" (every day) always matches,
     * regardless of which specific days were requested.
     *
     * @param  Builder<Promotion>  $query
     * @param  list<string>  $days
     * @return Builder<Promotion>
     */
    private function applyValidDays(Builder $query, array $days): Builder
    {
        return $query->where(function (Builder $q) use ($days) {
            $q->whereJsonContains('valid_days', 'Todos los días');

            foreach ($days as $day) {
                $q->orWhereJsonContains('valid_days', $day);
            }
        });
    }
}
