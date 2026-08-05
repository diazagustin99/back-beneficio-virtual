<?php

namespace App\Actions\Preferences;

use App\Models\Preference;
use App\Models\Wallet;

class FollowWalletAction
{
    public function handle(Preference $preference, Wallet $wallet): Preference
    {
        $preference->wallets()->syncWithoutDetaching([$wallet->id]);

        return $preference->load(['merchants', 'wallets', 'user']);
    }
}
