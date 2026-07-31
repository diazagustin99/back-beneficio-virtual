<?php

namespace App\Models;

use Database\Factories\MerchantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug', 'logo_url'])]
class Merchant extends Model
{
    /** @use HasFactory<MerchantFactory> */
    use HasFactory;

    /**
     * Keeps `normalized_name` in sync with `name` automatically — every
     * write path (`create`, `fill`, `update`) goes through this, so callers
     * never need to remember to set it themselves.
     */
    protected function setNameAttribute(string $value): void
    {
        $this->attributes['name'] = $value;
        $this->attributes['normalized_name'] = self::normalize($value);
    }

    /**
     * Lowercases, strips accents, and removes everything but letters/digits.
     * Used both as a whole-name dedupe key (`ChangoMás` / `Changomás` both
     * become `changomas`) and, word by word, to spot an existing merchant's
     * name hiding inside a longer string (see `ResolveMerchantAction`).
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
}
