<?php

namespace App\Actions\Preferences;

use App\Models\Merchant;
use App\Models\Preference;

class UnfollowMerchantAction
{
    public function handle(Preference $preference, Merchant $merchant): Preference
    {
        $preference->merchants()->detach($merchant->id);

        return $preference->load(['merchants', 'wallets', 'user']);
    }
}
