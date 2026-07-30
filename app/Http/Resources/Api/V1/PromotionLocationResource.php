<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromotionLocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'scope' => $this->scope,
            'province' => $this->province,
            'city' => $this->city,
            'address' => $this->address,
            'store_name' => $this->store_name,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];
    }
}
