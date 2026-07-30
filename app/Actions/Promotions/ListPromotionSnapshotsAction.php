<?php

namespace App\Actions\Promotions;

use App\Models\Promotion;
use App\Models\PromotionSnapshot;
use Illuminate\Database\Eloquent\Collection;

class ListPromotionSnapshotsAction
{
    /**
     * @return Collection<int, PromotionSnapshot>
     */
    public function handle(Promotion $promotion): Collection
    {
        return $promotion->snapshots()->orderByDesc('version')->get();
    }
}
