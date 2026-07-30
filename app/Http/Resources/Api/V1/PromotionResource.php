<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromotionResource extends JsonResource
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
            'external_id' => $this->external_id,
            'title' => $this->title,
            'description' => $this->description,
            'discount_percentage' => $this->discount_percentage,
            'fixed_amount' => $this->fixed_amount,
            'cashback_percentage' => $this->cashback_percentage,
            'installments' => $this->installments,
            'reimbursement_cap' => $this->reimbursement_cap,
            'minimum_purchase' => $this->minimum_purchase,
            'valid_days' => $this->valid_days,
            'starts_at' => $this->starts_at?->toDateString(),
            'ends_at' => $this->ends_at?->toDateString(),
            'terms' => $this->terms,
            'url' => $this->url,
            'version' => $this->version,
            'is_active' => $this->is_active,
            'first_seen_at' => $this->first_seen_at,
            'last_seen_at' => $this->last_seen_at,
            'deactivated_at' => $this->deactivated_at,
            'locations' => PromotionLocationResource::collection($this->whenLoaded('locations')),
            'payment_methods' => PromotionPaymentMethodResource::collection($this->whenLoaded('paymentMethods')),
        ];
    }
}
