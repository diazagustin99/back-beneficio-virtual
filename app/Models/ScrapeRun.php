<?php

namespace App\Models;

use App\Enums\ScrapeRunStatus;
use Database\Factories\ScrapeRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'wallet_id',
    'status',
    'triggered_by',
    'started_at',
    'finished_at',
    'promotions_total',
    'promotions_created',
    'promotions_updated',
    'promotions_unchanged',
    'promotions_deactivated',
    'promotions_failed',
    'error_message',
])]
class ScrapeRun extends Model
{
    /** @use HasFactory<ScrapeRunFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ScrapeRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Wallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * @return HasMany<PromotionSnapshot, $this>
     */
    public function promotionSnapshots(): HasMany
    {
        return $this->hasMany(PromotionSnapshot::class);
    }

    /**
     * @return HasMany<PromotionSource, $this>
     */
    public function promotionSources(): HasMany
    {
        return $this->hasMany(PromotionSource::class);
    }
}
