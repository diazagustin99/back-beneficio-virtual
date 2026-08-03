<?php

namespace App\Actions\Promotions;

use App\Models\Promotion;

class DeactivateExpiredPromotionsAction
{
    /**
     * Mark every active promotion whose `ends_at` has already passed as
     * inactive. The frontend always filters by `is_active` (never by
     * `ends_at` directly), so a promotion a scraper keeps returning past its
     * own expiration date would otherwise stay visible forever. Promotions
     * are never deleted.
     */
    public function handle(): int
    {
        return Promotion::query()
            ->where('is_active', true)
            ->whereNotNull('ends_at')
            ->whereDate('ends_at', '<', today())
            ->update([
                'is_active' => false,
                'deactivated_at' => now(),
            ]);
    }
}
