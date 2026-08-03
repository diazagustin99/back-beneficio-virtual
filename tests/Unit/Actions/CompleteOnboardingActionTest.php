<?php

namespace Tests\Unit\Actions;

use App\Actions\Preferences\CompleteOnboardingAction;
use App\Models\Merchant;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CompleteOnboardingActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_creates_a_preference_and_a_user_when_an_email_is_given(): void
    {
        $merchant = Merchant::factory()->create();
        $wallet = Wallet::factory()->create();

        ['preference' => $preference, 'email_taken' => $emailTaken] = (new CompleteOnboardingAction)->handle([
            'email' => 'nueva@example.com',
            'merchant_ids' => [$merchant->id],
            'wallet_ids' => [$wallet->id],
            'wants_notifications' => true,
        ]);

        $this->assertSame(40, strlen($preference->token));
        $this->assertSame('nueva@example.com', $preference->user->email);
        $this->assertFalse($emailTaken);
        $this->assertTrue($preference->wants_notifications);
        $this->assertTrue($preference->merchants->contains($merchant));
        $this->assertTrue($preference->wallets->contains($wallet));
        $this->assertSame(1, User::count());
    }

    public function test_creates_a_preference_without_a_user_when_no_email_is_given(): void
    {
        ['preference' => $preference, 'email_taken' => $emailTaken] = (new CompleteOnboardingAction)->handle([]);

        $this->assertNotEmpty($preference->token);
        $this->assertNull($preference->user);
        $this->assertFalse($emailTaken);
        $this->assertSame(0, User::count());
    }

    public function test_defaults_to_no_merchants_wallets_or_notifications(): void
    {
        ['preference' => $preference] = (new CompleteOnboardingAction)->handle([]);

        $this->assertFalse($preference->wants_notifications);
        $this->assertCount(0, $preference->merchants);
        $this->assertCount(0, $preference->wallets);
    }

    public function test_creates_the_preference_without_a_user_when_another_user_already_has_the_email(): void
    {
        User::factory()->create(['email' => 'ya@example.com']);

        ['preference' => $preference, 'email_taken' => $emailTaken] = (new CompleteOnboardingAction)->handle([
            'email' => 'ya@example.com',
        ]);

        $this->assertTrue($emailTaken);
        $this->assertNull($preference->user);
        $this->assertSame(1, User::count());
    }
}
