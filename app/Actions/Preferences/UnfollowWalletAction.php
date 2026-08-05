<?php

namespace App\Actions\Preferences;

use App\Models\Preference;
use App\Models\Wallet;

class UnfollowWalletAction
{
    public function handle(Preference $preference, Wallet $wallet): Preference
    {
        $preference->wallets()->detach($wallet->id);

        return $preference->load(['merchants', 'wallets', 'user']);
    }
}
