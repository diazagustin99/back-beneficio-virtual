<?php

namespace App\Actions\Scraping;

use App\Models\Wallet;
use Illuminate\Support\Str;

/**
 * Mirror of `ResolveMerchantAction` for the merchant-scraping pipeline (see
 * `MerchantScraperInterface`): resolves the raw bank name a supermarket
 * scraper found on its own page to a real wallet, creating one if it's
 * genuinely new — "en caso de no existir, crearlas", per the user's own
 * request. Every wallet this creates is real and `is_active` (unlike a
 * merchant's own scraper identity, a bank a supermarket names is always a
 * real payment wallet a user might want to follow), just without a scraper
 * of its own registered in config/scrapers.php.
 *
 * Returns `null` for a small, curated list of raw labels confirmed to *not*
 * be a real bank at all (a card network, a government installment program,
 * ...) — see `config/bank_wallet_aliases.php`'s own `skip` list for why.
 */
class ResolveWalletFromBankNameAction
{
    public function handle(string $rawBankName): ?Wallet
    {
        $trimmedName = trim($rawBankName);
        $normalized = Wallet::normalize($trimmedName);

        if (in_array($normalized, config('bank_wallet_aliases.skip', []), true)) {
            return null;
        }

        // A bank spelled differently across supermarkets (e.g. "Banco
        // Galicia" vs. "GALICIA") resolves to the real wallet's own name
        // instead of creating a near-duplicate — see
        // config/bank_wallet_aliases.php.
        $resolvedName = config('bank_wallet_aliases.aliases.'.$normalized) ?? $trimmedName;
        $slug = Str::slug($resolvedName);

        // Belt-and-suspenders beyond the alias map above: a handful of
        // existing wallets have a `slug` that isn't `Str::slug(name)`
        // (e.g. "Banco Galicia" is slugged "galicia", not
        // "banco-galicia" — confirmed live: a bare "Galicia" mention with
        // no alias entry collided on the unique `slug` constraint instead
        // of finding the existing row). Falling back to a slug match
        // before creating catches that case even without a hand-curated
        // alias for every such wallet.
        return Wallet::where('normalized_name', Wallet::normalize($resolvedName))->first()
            ?? Wallet::where('slug', $slug)->first()
            ?? Wallet::create([
                'name' => $resolvedName,
                'slug' => $slug,
                'is_active' => true,
            ]);
    }
}
