<?php

namespace App\Actions\Promotions;

use App\Models\Promotion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class ListPromotionsAction
{
    /**
     * @param  array{wallet?: string, merchant_id?: int, promotion_category_id?: int, is_active?: bool, valid_on?: string, search?: string, per_page?: int}  $filters
     */
    public function handle(array $filters = []): LengthAwarePaginator
    {
        return Promotion::query()
            ->with(['wallet', 'merchant', 'category'])
            ->when(filled($filters['wallet'] ?? null), fn ($query) => $query->forWallet($filters['wallet']))
            ->when(filled($filters['merchant_id'] ?? null), fn ($query) => $query->where('merchant_id', $filters['merchant_id']))
            ->when(filled($filters['promotion_category_id'] ?? null), fn ($query) => $query->where('promotion_category_id', $filters['promotion_category_id']))
            ->when(array_key_exists('is_active', $filters), fn ($query) => $query->where('is_active', $filters['is_active']))
            ->when(filled($filters['valid_on'] ?? null), fn ($query) => $query->validOn(Carbon::parse($filters['valid_on'])))
            ->when(filled($filters['search'] ?? null), fn ($query) => $query->where('title', 'like', '%'.$filters['search'].'%'))
            ->latest('last_seen_at')
            ->paginate($filters['per_page'] ?? 15);
    }
}
