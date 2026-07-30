<?php

namespace App\Models;

use Database\Factories\PromotionPaymentMethodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['promotion_id', 'name'])]
class PromotionPaymentMethod extends Model
{
    /** @use HasFactory<PromotionPaymentMethodFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Promotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }
}
