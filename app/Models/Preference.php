<?php

namespace App\Models;

use Database\Factories\PreferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Notifications\Notifiable;
use NotificationChannels\WebPush\HasPushSubscriptions;

/**
 * The session anchor for onboarding: created for every visitor who finishes
 * the wizard, whether or not they give an email. `token` is what the
 * frontend keeps in `localStorage`; `user_id` is only set when an email was
 * given and wasn't already taken by another user (see `CompleteOnboardingAction`).
 */
#[Fillable(['token', 'user_id', 'wants_notifications'])]
class Preference extends Model
{
    /** @use HasFactory<PreferenceFactory> */
    use HasFactory, HasPushSubscriptions, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'wants_notifications' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<Merchant, $this>
     */
    public function merchants(): BelongsToMany
    {
        return $this->belongsToMany(Merchant::class);
    }

    /**
     * @return BelongsToMany<Wallet, $this>
     */
    public function wallets(): BelongsToMany
    {
        // Explicit table name: Eloquent's alphabetical convention would
        // otherwise expect `preference_wallet`, not `wallet_preference`.
        return $this->belongsToMany(Wallet::class, 'wallet_preference');
    }
}
