<?php

namespace App\Actions\Preferences;

use App\Models\Preference;

class ShowPreferenceAction
{
    public function handle(Preference $preference): Preference
    {
        return $preference->load(['merchants', 'wallets', 'user']);
    }
}
