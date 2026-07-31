<?php

namespace App\Models;

use Database\Factories\PromotionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable([
    'wallet_id',
    'merchant_id',
    'promotion_category_id',
    'last_scrape_run_id',
    'external_id',
    'identity_hash',
    'title',
    'description',
    'discount_percentage',
    'fixed_amount',
    'cashback_percentage',
    'installments',
    'reimbursement_cap',
    'minimum_purchase',
    'valid_days',
    'starts_at',
    'ends_at',
    'terms',
    'url',
    'version',
    'is_active',
    'first_seen_at',
    'last_seen_at',
    'deactivated_at',
])]
class Promotion extends Model
{
    /** @use HasFactory<PromotionFactory> */
    use HasFactory;

    /**
     * Columns that participate in change detection between scrape runs.
     * Snapshotted to `promotion_snapshots` before being overwritten.
     *
     * @var list<string>
     */
    public const array TRACKED_FIELDS = [
        'merchant_id',
        'promotion_category_id',
        'title',
        'description',
        'discount_percentage',
        'fixed_amount',
        'cashback_percentage',
        'installments',
        'reimbursement_cap',
        'minimum_purchase',
        'valid_days',
        'starts_at',
        'ends_at',
        'terms',
        'url',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discount_percentage' => 'decimal:2',
            'fixed_amount' => 'decimal:2',
            'cashback_percentage' => 'decimal:2',
            'reimbursement_cap' => 'decimal:2',
            'minimum_purchase' => 'decimal:2',
            'valid_days' => 'array',
            'starts_at' => 'date',
            'ends_at' => 'date',
            'is_active' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'deactivated_at' => 'datetime',
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
     * @return BelongsTo<Merchant, $this>
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * @return BelongsTo<PromotionCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(PromotionCategory::class, 'promotion_category_id');
    }

    /**
     * @return BelongsTo<ScrapeRun, $this>
     */
    public function lastScrapeRun(): BelongsTo
    {
        return $this->belongsTo(ScrapeRun::class, 'last_scrape_run_id');
    }

    /**
     * @return HasMany<PromotionLocation, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(PromotionLocation::class);
    }

    /**
     * @return HasMany<PromotionPaymentMethod, $this>
     */
    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PromotionPaymentMethod::class);
    }

    /**
     * @return HasMany<PromotionSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(PromotionSnapshot::class);
    }

    /**
     * @return HasMany<PromotionSource, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(PromotionSource::class);
    }

    /**
     * @param  Builder<Promotion>  $query
     * @return Builder<Promotion>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<Promotion>  $query
     * @param  list<string>  $walletSlugs
     * @return Builder<Promotion>
     */
    public function scopeForWallets(Builder $query, array $walletSlugs): Builder
    {
        return $query->whereHas('wallet', fn (Builder $wallet) => $wallet->whereIn('slug', $walletSlugs));
    }

    /**
     * @param  Builder<Promotion>  $query
     * @return Builder<Promotion>
     */
    public function scopeValidOn(Builder $query, Carbon $date): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhereDate('starts_at', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', $date));
    }
}
