<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class MerchantDiscountsTodayNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array{id: int, name: string, promotions_count: int}>  $merchants
     */
    public function __construct(
        public array $merchants,
        public string $date,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        // WebPushChannel is a no-op when the user has no push_subscriptions
        // rows, so it's always safe to include regardless of browser opt-in.
        return ['database', WebPushChannel::class];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(mixed $notifiable): array
    {
        return [
            'date' => $this->date,
            'merchants' => $this->merchants,
        ];
    }

    public function toWebPush(mixed $notifiable, mixed $notification): WebPushMessage
    {
        $names = collect($this->merchants)->pluck('name');

        $body = $names->count() > 3
            ? $names->take(3)->implode(', ').' y '.($names->count() - 3).' más'
            : $names->implode(', ');

        return (new WebPushMessage)
            ->title('Beneficio Virtual')
            ->icon('/pwa-192x192.png')
            ->body("Hoy tenés descuentos en: {$body}")
            ->data(['url' => '/notifications']);
    }
}
