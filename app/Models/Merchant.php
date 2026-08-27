<?php

namespace App\Models;

use Database\Factories\MerchantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
     * Strips a trailing "con MODO" (and anything after it, e.g. "- Plan
     * Sueldo") from a merchant name — confirmed live on Supervielle's own
     * `marca` field: the exact same real business appears under several
     * different `marca` strings depending on payment-method eligibility
     * (e.g. "AG Piazze con MODO" and "AG Piazze con MODO - Plan Sueldo" for
     * the identical business), which would otherwise create several junk
     * merchants for one real one. "X con MODO" is never itself a real
     * business name, so this is applied both when a scraper first resolves
     * the name (`ResolveMerchantAction`, via `SupervielleScraper`) and
     * retroactively against merchants a scrape already created
     * (`MergeDuplicateMerchantsAction`).
     */
    public static function stripModoSuffix(string $value): string
    {
        $stripped = preg_replace('/\s+con\s+modo\b.*$/iu', '', $value);

        return $stripped !== null && trim($stripped) !== '' ? trim($stripped) : $value;
    }

    /**
     * Strips a trailing "con Visa" (and anything after it, e.g. "débito
     * NFC", "Signature NFC") from a merchant name — the same "marca
     * carries the payment-method eligibility" pattern as `stripModoSuffix()`,
     * confirmed live on Supervielle: "COTO con Visa débito NFC" is the real
     * "Coto" supermarket chain, not a different business.
     */
    public static function stripVisaSuffix(string $value): string
    {
        $stripped = preg_replace('/\s+con\s+visa\b.*$/iu', '', $value);

        return $stripped !== null && trim($stripped) !== '' ? trim($stripped) : $value;
    }

    /**
     * The known customer-segment qualifiers Supervielle appends to `marca`
     * with a leading dash — confirmed live: the same real business gets a
     * separate `marca` per eligible segment (e.g. "Disco - Jubilados",
     * "Jumbo - Jubilados", "Vea - Jubilados", "Almundo - Paquetes"). Never
     * matched without the dash: several *real* merchant names genuinely
     * contain "Jubilados" as an ordinary word, not a qualifier suffix —
     * "Centro de Jubilados y Pens. de Ctes", "DESAYUNOS JUBILADOS",
     * "ChangoMás Jubilados" — so only the "- {qualifier}" shape strips,
     * never the bare word wherever it appears.
     *
     * @var list<string>
     */
    private const array SEGMENT_QUALIFIERS = ['Plan Sueldo', 'Jubilados', 'Paquetes'];

    /**
     * Strips a trailing "- {qualifier}" (see `SEGMENT_QUALIFIERS`) from a
     * merchant name. Applied after `stripModoSuffix()` wherever a
     * Supervielle name is cleaned: that already consumes "- Plan Sueldo"
     * when it follows "con MODO", but "Jubilados"/"Paquetes" also appear as
     * their own, MODO-independent suffix (e.g. "Disco - Jubilados", no
     * "con MODO" involved at all).
     */
    public static function stripSegmentQualifierSuffix(string $value): string
    {
        $qualifiers = implode('|', array_map(fn (string $q) => preg_quote($q, '/'), self::SEGMENT_QUALIFIERS));
        $stripped = preg_replace('/\s*-\s*(?:'.$qualifiers.')\s*$/iu', '', $value);

        return $stripped !== null && trim($stripped) !== '' ? trim($stripped) : $value;
    }

    /**
     * The payment-method tags Macro's own `card-benefits` API embeds
     * directly in a promo's `name` field — confirmed live: the exact same
     * business appears both under its clean name and under a variant with
     * one or more of these tags appended (e.g. "HAVANNA GOOGLE PAY APPLE
     * PAY" alongside the already-existing clean "Havanna"). Unlike the
     * Supervielle suffixes above, these can appear anywhere in the string,
     * not just trailing — Macro's own feed has cases like "MISCUSI GOOGLE
     * PAY MISCUSI APPLE PAY" — so every occurrence is stripped, not just one
     * anchored at the end.
     *
     * @var list<string>
     */
    private const array PAYMENT_METHOD_TAGS = ['GOOGLE PAY', 'APPLE PAY', 'MODO NFC'];

    /**
     * Strips every occurrence of `PAYMENT_METHOD_TAGS` from a merchant name
     * and collapses the whitespace left behind. Applied both when Macro's
     * scraper first resolves a name and retroactively against merchants an
     * earlier scrape already created (`MergeDuplicateMerchantsAction`).
     *
     * Macro's feed doesn't just append these tags — some entries repeat the
     * whole name once per tag instead (e.g. "MISCUSI GOOGLE PAY MISCUSI
     * APPLE PAY", confirmed live), which stripping the tags alone would
     * leave as "Miscusi Miscusi". `collapseExactRepeat()` cleans that up
     * too — but only an *exact*, whole-string repeat: "El Chucaro Cerro El
     * Chucaro Cerro Appl" (a truncated second half, also seen live) is left
     * alone rather than guessed at.
     *
     * The repeat-collapse runs even when no tag was actually found (a name
     * with no tag to strip is just handed to it unchanged first) — running
     * `merchants:merge-duplicates` for real also caught plain "Clau Clau" ->
     * "Clau" and "Bella Bella" -> "Bella" this way, no tag involved at all.
     * Deliberate: Macro's feed doubling names is the same root issue either
     * way, and a real business whose *own* name is deliberately the same
     * word repeated (a "Duran Duran"-style case) hasn't been seen in this
     * catalog — if one ever turns up, exclude it here explicitly rather
     * than narrowing this back down.
     */
    public static function stripPaymentMethodTags(string $value): string
    {
        $pattern = implode('|', array_map(fn (string $tag) => preg_quote($tag, '/'), self::PAYMENT_METHOD_TAGS));
        $stripped = preg_replace('/\b(?:'.$pattern.')\b/iu', '', $value);
        $stripped = $stripped !== null ? trim(preg_replace('/\s+/', ' ', $stripped) ?? $stripped) : '';

        return $stripped !== '' ? self::collapseExactRepeat($stripped) : $value;
    }

    /**
     * Collapses a string made of the exact same phrase twice in a row,
     * separated by whitespace (e.g. "Mas Historias Mas Historias" ->
     * "Mas Historias"), case-insensitively. Returns `$value` unchanged if
     * it isn't shaped that way.
     */
    private static function collapseExactRepeat(string $value): string
    {
        return preg_replace('/^(.+?)\s+\1$/iu', '$1', $value) ?? $value;
    }

    /**
     * @return HasMany<Promotion, $this>
     */
    public function promotions(): HasMany
    {
        return $this->hasMany(Promotion::class);
    }

    /**
     * A merchant is only ever the scraper *source* for the supermarket
     * pipeline (see `MerchantScraperInterface`) — the promotions it
     * receives via a wallet's own scrape use `promotions()` above instead.
     *
     * @return MorphMany<ScrapeRun, $this>
     */
    public function scrapeRuns(): MorphMany
    {
        return $this->morphMany(ScrapeRun::class, 'scrapeable');
    }
}
