<?php

namespace App\Actions\Scraping;

use App\Models\Merchant;
use Illuminate\Support\Str;

class ResolveMerchantAction
{
    public function handle(string $name, ?string $iconUrl = null): Merchant
    {
        $slug = Str::slug($name);

        $merchant = Merchant::createOrFirst(['slug' => $slug], [
            'name' => trim($name),
            'slug' => $slug,
            'logo_url' => $iconUrl,
        ]);

        if ($iconUrl !== null && $merchant->logo_url !== $iconUrl) {
            $merchant->update(['logo_url' => $iconUrl]);
        }

        return $merchant;
    }
}
