<?php

namespace App\Actions\Preferences;

use App\Models\Merchant;
use App\Models\Preference;

class FollowMerchantAction
{
    public function handle(Preference $preference, Merchant $merchant): Preference
    {
        $preference->merchants()->syncWithoutDetaching([$merchant->id]);

        return $preference->load(['merchants', 'wallets', 'user']);
    }
}
