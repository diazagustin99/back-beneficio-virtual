<?php

namespace App\Models;

use Database\Factories\PromotionCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug'])]
class PromotionCategory extends Model
{
    /** @use HasFactory<PromotionCategoryFactory> */
    use HasFactory;

    /**
     * @return HasMany<Promotion, $this>
     */
    public function promotions(): HasMany
    {
        return $this->hasMany(Promotion::class);
    }

    /**
     * Lowercases, strips accents, and removes everything but letters/digits
     * — used to compare individual *words* of a category name
     * (`CategoryWordMatcher`), not the whole name itself (that's `slug`,
     * via `Str::slug()`). Same rule as `Wallet::normalize()`/
     * `Merchant::normalize()`, kept as its own copy for the same reason
     * those two are: an unrelated concept that just happens to need the
     * same string-cleanup rule.
     */
    public static function normalize(string $value): string
    {
        return mb_strtolower(preg_replace('/[^a-zA-Z0-9]/', '', Str::ascii($value)));
    }
}
