<?php

namespace App\Actions\Preferences;

use App\Models\Preference;

class CountUnreadPreferenceNotificationsAction
{
    public function handle(Preference $preference): int
    {
        return $preference->unreadNotifications()->count();
    }
}
