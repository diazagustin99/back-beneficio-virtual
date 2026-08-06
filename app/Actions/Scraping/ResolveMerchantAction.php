<?php

namespace App\Actions\Scraping;

use App\Models\Merchant;
use App\Services\Scraping\MerchantWordMatcher;
use Illuminate\Support\Str;

class ResolveMerchantAction
{
    public function __construct(
        private readonly MerchantWordMatcher $wordMatcher,
    ) {}

    public function handle(string $name, ?string $iconUrl = null): Merchant
    {
        $trimmedName = trim($name);

        // A variant name (e.g. "Aerolinea Arg") resolves to the canonical
        // merchant's real name (e.g. "Aerolíneas Argentinas") instead of
        // creating a near-duplicate — see config/merchant_aliases.php.
        $resolvedName = config('merchant_aliases.'.Merchant::normalize($trimmedName)) ?? $trimmedName;

        $merchant = Merchant::where('normalized_name', Merchant::normalize($resolvedName))->first()
            ?? $this->wordMatcher->findSingleMatch($resolvedName);

        if ($merchant === null) {
            $merchant = Merchant::create([
                'name' => $resolvedName,
                'slug' => Str::slug($resolvedName),
                'logo_url' => $iconUrl,
            ]);
        } elseif ($iconUrl !== null && $merchant->logo_url !== $iconUrl) {
            $merchant->update(['logo_url' => $iconUrl]);
        }

        return $merchant;
    }
}
