<?php

namespace App\Actions\Preferences;

use App\Models\Preference;
use App\Models\User;
use Illuminate\Support\Str;

class CompleteOnboardingAction
{
    /**
     * A `Preference` (the session behind the local token) is created either
     * way. A `User` row — pure email identity — is only created when an
     * email is given and isn't already taken by someone else; if it is
     * taken, the `Preference` is still created without an attached email
     * instead of failing the whole onboarding over a duplicate.
     *
     * @param  array{email?: string|null, merchant_ids?: list<int>, wallet_ids?: list<int>, wants_notifications?: bool}  $data
     * @return array{preference: Preference, email_taken: bool}
     */
    public function handle(array $data): array
    {
        $email = $data['email'] ?? null;
        $emailTaken = $email !== null && User::where('email', $email)->exists();

        $user = ($email !== null && ! $emailTaken) ? User::create(['email' => $email]) : null;

        $preference = Preference::create([
            'token' => $this->generateUniqueToken(),
            'user_id' => $user?->id,
            'wants_notifications' => $data['wants_notifications'] ?? false,
        ]);

        $preference->merchants()->sync($data['merchant_ids'] ?? []);
        $preference->wallets()->sync($data['wallet_ids'] ?? []);

        return ['preference' => $preference->load(['merchants', 'wallets', 'user']), 'email_taken' => $emailTaken];
    }

    /**
     * Random, so it can (rarely) collide — retried instead of trusting the
     * unique index alone, which would otherwise surface as a raw SQL error.
     */
    private function generateUniqueToken(): string
    {
        do {
            $token = Str::random(40);
        } while (Preference::where('token', $token)->exists());

        return $token;
    }
}
