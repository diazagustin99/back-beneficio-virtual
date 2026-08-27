<?php

namespace App\Models;

use Database\Factories\WalletFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug', 'is_active'])]
class Wallet extends Model
{
    /** @use HasFactory<WalletFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Keeps `normalized_name` in sync with `name` automatically — same
     * mutator pattern as `Merchant`, so `ResolveWalletFromBankNameAction`
     * can match "Banco Galicia" / "GALICIA" / "banco galicia" (different
     * supermarket sites spell the same bank differently) against the one
     * real wallet instead of creating a near-duplicate.
     */
    protected function setNameAttribute(string $value): void
    {
        $this->attributes['name'] = $value;
        $this->attributes['normalized_name'] = self::normalize($value);
    }

    /**
     * Lowercases, strips accents, and removes everything but letters/digits
     * — identical rule to `Merchant::normalize()`, kept as its own copy
     * since a wallet's name and a merchant's name are unrelated concepts
     * that just happen to need the same string-cleanup rule.
     */
    public static function normalize(string $value): string
    {
        return mb_strtolower(preg_replace('/[^a-zA-Z0-9]/', '', Str::ascii($value)));
    }

    /**
     * @return HasMany<Promotion, $this>
     */
    public function promotions(): HasMany
    {
        return $this->hasMany(Promotion::class);
    }

    /**
     * @return MorphMany<ScrapeRun, $this>
     */
    public function scrapeRuns(): MorphMany
    {
        return $this->morphMany(ScrapeRun::class, 'scrapeable');
    }

    /**
     * @param  Builder<Wallet>  $query
     * @return Builder<Wallet>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
