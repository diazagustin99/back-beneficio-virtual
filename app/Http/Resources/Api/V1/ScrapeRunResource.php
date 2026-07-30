<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScrapeRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'wallet' => new WalletResource($this->whenLoaded('wallet')),
            'status' => $this->status,
            'triggered_by' => $this->triggered_by,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'promotions_total' => $this->promotions_total,
            'promotions_created' => $this->promotions_created,
            'promotions_updated' => $this->promotions_updated,
            'promotions_unchanged' => $this->promotions_unchanged,
            'promotions_deactivated' => $this->promotions_deactivated,
            'promotions_failed' => $this->promotions_failed,
            'error_message' => $this->error_message,
        ];
    }
}
