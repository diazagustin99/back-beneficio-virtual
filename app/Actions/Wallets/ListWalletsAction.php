<?php

namespace App\Actions\Wallets;

use App\Models\Wallet;
use Illuminate\Database\Eloquent\Collection;

class ListWalletsAction
{
    /**
     * @param  array{is_active?: bool}  $filters
     * @return Collection<int, Wallet>
     */
    public function handle(array $filters = []): Collection
    {
        return Wallet::query()
            ->when(
                array_key_exists('is_active', $filters),
                fn ($query) => $query->where('is_active', $filters['is_active']),
            )
            ->latest()
            ->get();
    }
}
