<?php

namespace App\Actions\Preferences;

use App\Models\Preference;
use Illuminate\Notifications\DatabaseNotification;

class MarkNotificationReadAction
{
    public function handle(Preference $preference, string $notificationId): DatabaseNotification
    {
        /** @var DatabaseNotification $notification */
        $notification = $preference->notifications()->findOrFail($notificationId);
        $notification->markAsRead();

        return $notification;
    }
}
