<?php

namespace App\Actions\Merchants;

use App\Models\Merchant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListMerchantsAction
{
    /**
     * @param  array{search?: string, per_page?: int}  $filters
     */
    public function handle(array $filters = []): LengthAwarePaginator
    {
        return Merchant::query()
            ->when(
                filled($filters['search'] ?? null),
                fn ($query) => $query->where('name', 'like', '%'.$filters['search'].'%'),
            )
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 15);
    }
}
