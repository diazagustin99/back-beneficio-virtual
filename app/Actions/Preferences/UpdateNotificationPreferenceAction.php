<?php

namespace App\Actions\Preferences;

use App\Models\Preference;

class UpdateNotificationPreferenceAction
{
    /**
     * Lets a `Preference` turn push/in-app notifications on or off after
     * onboarding — with or without an email attached, since the flag lives
     * entirely on the Preference, not the User.
     */
    public function handle(Preference $preference, bool $wantsNotifications): Preference
    {
        $preference->update(['wants_notifications' => $wantsNotifications]);

        return $preference->load(['merchants', 'wallets', 'user']);
    }
}
