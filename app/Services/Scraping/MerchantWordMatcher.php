<?php

namespace App\Services\Scraping;

use App\Models\Merchant;

/**
 * Finds a *known* merchant chain "hiding" inside a longer string — e.g.
 * Cuenta DNI gives promotional sentences instead of clean names ("Ahorrá en
 * Carrefour", "¡Especial Carrefour de los sábados!") that would otherwise
 * each create their own junk merchant instead of resolving to "Carrefour".
 *
 * This deliberately does NOT match against the entire merchant catalog.
 * An earlier version did, matching any word against any existing merchant
 * name, and it corrupted real data the first time it ran for real: the
 * catalog already has junk single-word "merchants" from unrelated scraping
 * noise (e.g. a merchant literally named "Repuestos" — "spare parts"), so
 * seven unrelated auto parts shops ("Repuestos Ford", "Repuestos Nea", ...)
 * all matched that one word and got silently merged together. Matching
 * against a short, hand-verified list of real chain names — never a plain
 * Spanish word ("Día", "Vea" excluded on purpose, see below) — avoids that
 * failure mode entirely, at the cost of only catching the specific chains
 * listed here instead of anything in the catalog.
 *
 * A second, narrower incident: "Riadigos Carrefour" is a pharmacy chain
 * ("Riadigos") with a stand inside a Carrefour hypermarket — a genuinely
 * different business, not Carrefour itself — but it does contain the word
 * "Carrefour". Matching requires every *other* significant word in the name
 * to be recognized promotional/schedule filler (`ahorra`, `especial`,
 * weekday names, ...); "Riadigos" isn't, so the match is refused instead of
 * guessed.
 */
class MerchantWordMatcher
{
    /**
     * Verified via the raw Cuenta DNI payload to have the "sentence instead
     * of a name" problem. Chains whose name is *also* an ordinary Spanish
     * word — "Día" (day), "Vea" (look!), "Jumbo", "Disco" — are deliberately
     * left out: "¡Especial Día de la Madre!" (Mother's Day) would otherwise
     * wrongly resolve to the "Día" supermarket chain.
     *
     * @var list<string>
     */
    private const array KNOWN_CHAINS = ['carrefour', 'coto', 'changomas'];

    /**
     * Grammatical connectors — never carry meaning, always ignored.
     *
     * @var list<string>
     */
    private const array STOPWORDS = [
        'de', 'la', 'el', 'los', 'las', 'en', 'con', 'para', 'por', 'un', 'una',
        'y', 'del', 'al', 'tu', 'tus', 'su', 'sus', 'a', 'o', 'que', 'sin',
    ];

    /**
     * Common promotional/schedule vocabulary the real Cuenta DNI sentences
     * are actually built from — allowed to appear alongside a known chain
     * name without blocking the match. Any *other* significant word (like
     * "Riadigos") means this is probably a different, specific business
     * that just happens to mention the chain, so the match is refused.
     *
     * @var list<string>
     */
    private const array PROMOTIONAL_FILLER_WORDS = [
        'ahorra', 'aprovecha', 'disfruta', 'especial', 'super', 'promo', 'promocion',
        'paga', 'descuento', 'reintegro', 'cuotas', 'nfc', 'qr', 'app', 'online',
        'todos', 'dia', 'dias',
        'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'sabados', 'domingo', 'domingos',
    ];

    /**
     * Only resolves when exactly one distinct existing merchant matches a
     * known-chain token found in the name, and every other significant word
     * in it is recognized promotional filler.
     *
     * @param  int|null  $excludeMerchantId  Excluded from candidates — used when
     *                                       re-checking a merchant that already
     *                                       exists against its peers, so it never
     *                                       "matches" itself.
     */
    public function findSingleMatch(string $name, ?int $excludeMerchantId = null): ?Merchant
    {
        $tokens = $this->tokenize($name);
        $chainTokens = array_intersect($tokens, self::KNOWN_CHAINS);

        if ($chainTokens === [] || $this->hasUnrecognizedWords($tokens)) {
            return null;
        }

        $matches = Merchant::query()
            ->whereIn('normalized_name', $chainTokens)
            ->when($excludeMerchantId !== null, fn ($query) => $query->where('id', '!=', $excludeMerchantId))
            ->get();

        return $matches->pluck('id')->unique()->count() === 1 ? $matches->first() : null;
    }

    /**
     * Whether `$name` is even worth checking — i.e. it mentions a known
     * chain at all.
     */
    public function mentionsAKnownChain(string $name): bool
    {
        return array_intersect($this->tokenize($name), self::KNOWN_CHAINS) !== [];
    }

    /**
     * True if any token isn't a stopword, filler word, or known chain —
     * i.e. a word that could itself be identifying a different business.
     *
     * @param  list<string>  $tokens
     */
    private function hasUnrecognizedWords(array $tokens): bool
    {
        return array_diff($tokens, self::STOPWORDS, self::PROMOTIONAL_FILLER_WORDS, self::KNOWN_CHAINS) !== [];
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $name): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_map(fn ($token) => Merchant::normalize($token), $tokens)));
    }
}
