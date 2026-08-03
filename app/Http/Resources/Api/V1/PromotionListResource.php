<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromotionListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'wallet' => new WalletResource($this->whenLoaded('wallet')),
            'merchant' => new MerchantResource($this->whenLoaded('merchant')),
            'category' => new PromotionCategoryResource($this->whenLoaded('category')),
            'title' => $this->title,
            'description' => $this->description,
            'discount_percentage' => $this->discount_percentage,
            'fixed_amount' => $this->fixed_amount,
            'cashback_percentage' => $this->cashback_percentage,
            'installments' => $this->installments,
            'valid_days' => $this->valid_days,
            'starts_at' => $this->starts_at?->toDateString(),
            'ends_at' => $this->ends_at?->toDateString(),
            'is_active' => $this->is_active,
            'url' => $this->url,
        ];
    }
}
