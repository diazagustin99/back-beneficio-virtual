<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PreferenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'token' => $this->token,
            'email' => $this->user?->email,
            'wants_notifications' => $this->wants_notifications,
            'merchants' => MerchantResource::collection($this->whenLoaded('merchants')),
            'wallets' => WalletResource::collection($this->whenLoaded('wallets')),
        ];
    }
}
