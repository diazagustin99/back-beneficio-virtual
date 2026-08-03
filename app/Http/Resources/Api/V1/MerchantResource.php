<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MerchantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'logo_url' => $this->logo_url,
            'promotions_count' => $this->whenCounted('promotions'),
            'wallets' => $this->when(
                $this->relationLoaded('promotions'),
                fn () => WalletResource::collection(
                    $this->promotions->pluck('wallet')->filter()->unique('id')->values(),
                ),
            ),
        ];
    }
}
