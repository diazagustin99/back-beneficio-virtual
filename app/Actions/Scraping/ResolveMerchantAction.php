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

        $merchant = Merchant::where('normalized_name', Merchant::normalize($trimmedName))->first()
            ?? $this->wordMatcher->findSingleMatch($trimmedName);

        if ($merchant === null) {
            $merchant = Merchant::create([
                'name' => $trimmedName,
                'slug' => Str::slug($trimmedName),
                'logo_url' => $iconUrl,
            ]);
        } elseif ($iconUrl !== null && $merchant->logo_url !== $iconUrl) {
            $merchant->update(['logo_url' => $iconUrl]);
        }

        return $merchant;
    }
}
