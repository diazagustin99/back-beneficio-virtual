<?php

namespace App\Actions\Preferences;

use App\Models\Preference;

class StorePushSubscriptionAction
{
    /**
     * @param  array{endpoint: string, keys: array{p256dh?: string, auth?: string}}  $subscription
     */
    public function handle(Preference $preference, array $subscription): void
    {
        $preference->updatePushSubscription(
            endpoint: $subscription['endpoint'],
            key: $subscription['keys']['p256dh'] ?? null,
            token: $subscription['keys']['auth'] ?? null,
        );
    }
}
