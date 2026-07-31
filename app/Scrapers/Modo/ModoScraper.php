<?php

namespace App\Scrapers\Modo;

use App\Contracts\Scrapers\WalletScraperInterface;
use App\DTOs\PromotionDTO;
use App\Scrapers\Concerns\MakesHttpRequests;
use App\Scrapers\Concerns\ParsesForeignDates;
use Throwable;

/**
 * modo.com.ar/promos is a Next.js App Router site backed by its own REST
 * API under /promos/api/rewards — by far the richest and largest catalog of
 * the six wallets (roughly 780 promotions across ~8 pages of 100).
 *
 * The listing endpoint (`/rewards/slots`) has no terms/legal field, and its
 * `short_description` is an internal campaign code (e.g.
 * "2507-Macro-AlbalaPérgola-Presencial-20off"), not something a user should
 * read. Each promo's own detail page (`/promos/{slug}`) has real, readable
 * detail — confirmed live by opening one from the listing. It's a Next.js
 * page, so the data isn't a plain REST JSON response: it's embedded in the
 * HTML as React Server Component ("Flight") payloads, inside
 * `self.__next_f.push([1, "..."])` script calls. One of those payloads
 * decodes to a JSON object (`card`, `meta`, `promotion`, `trigger_params`,
 * `sections`, ...) with the reintegro cap, minimum purchase, a precise
 * discount-vs-cashback flag (`trigger_params.discount_mode`) and a
 * plain-language usage FAQ — none of which the listing exposes. The full
 * legal "BASES Y CONDICIONES" text sits one level deeper, behind another
 * Flight back-reference (e.g. `"$24"` pointing at a separately-numbered
 * chunk) — reconstructing that reliably was judged not worth the added
 * fragility, so `terms` stays null; the FAQ text becomes `description`.
 *
 * This second call is enrichment only, done in a second, separate pass
 * *after* the whole listing has already been paginated through — never
 * interleaved with it, and never allowed to abort the scrape: any failure
 * (network error, unexpected page shape, a `push()` payload that doesn't
 * decode) just leaves that one promo with its listing-only fields.
 */
class ModoScraper implements WalletScraperInterface
{
    use MakesHttpRequests;
    use ParsesForeignDates;

    private const string CATEGORIES_URL = 'https://www.modo.com.ar/promos/api/rewards/categories';

    private const string SLOTS_URL = 'https://www.modo.com.ar/promos/api/rewards/slots';

    private const string DETAIL_URL = 'https://www.modo.com.ar/promos/';

    private const string REFERER = 'https://www.modo.com.ar/promos';

    private const int PAGE_SIZE = 100;

    /**
     * @var array<string, string>
     */
    private const array DAY_LETTERS = [
        'L' => 'Lunes', 'M' => 'Martes', 'X' => 'Miércoles', 'J' => 'Jueves',
        'V' => 'Viernes', 'S' => 'Sábado', 'D' => 'Domingo',
    ];

    public function walletSlug(): string
    {
        return 'modo';
    }

    public function scrape(): iterable
    {
        $categories = $this->fetchCategoryMap();

        // Stage 1: paginate the whole listing to completion first — this
        // array only holds raw cards (a few hundred KB of JSON), not DTOs.
        $cards = $this->fetchAllCards();

        // Stage 2: only now, one promo at a time, try to enrich with its
        // own detail page. Best-effort — see the class docblock.
        foreach ($cards as $card) {
            $dto = $this->cardToDto($card, $categories);

            if ($dto !== null) {
                yield $dto;
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchAllCards(): array
    {
        $allCards = [];
        $page = 1;
        $totalPages = 1;

        do {
            $response = $this->fetchPage($page);
            $cards = $response['data']['cards'] ?? [];
            $totalPages = (int) ($response['metadata']['pagination']['total_pages'] ?? 1);

            foreach (is_array($cards) ? $cards : [] as $card) {
                if (is_array($card)) {
                    $allCards[] = $card;
                }
            }

            $page++;
        } while ($page <= $totalPages);

        return $allCards;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchPage(int $page): array
    {
        $response = $this->http()
            ->withHeaders(['Referer' => self::REFERER])
            ->get(self::SLOTS_URL, [
                'slots' => 'web-modo-hub-mas-promos',
                'limit' => self::PAGE_SIZE,
                'page' => $page,
                'search_text' => '',
                'source' => 'web_modo',
                'origin' => 'web_modo',
                'fcalcstatus' => 'running',
                'slot_info' => 'true',
                'categories' => '',
                'banks' => '',
                'user_bank_ids' => '',
            ])
            ->throw()
            ->json();

        return is_array($response) ? $response : [];
    }

    /**
     * @return array<int, string>
     */
    private function fetchCategoryMap(): array
    {
        $categories = $this->http()
            ->withHeaders(['Referer' => self::REFERER])
            ->get(self::CATEGORIES_URL, ['subcategories' => 'true'])
            ->throw()
            ->json();

        if (! is_array($categories)) {
            return [];
        }

        $map = [];

        foreach ($categories as $category) {
            if (is_array($category) && isset($category['id'], $category['title']) && is_string($category['title'])) {
                $map[(int) $category['id']] = $category['title'];
            }
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $card
     * @param  array<int, string>  $categories
     */
    private function cardToDto(array $card, array $categories): ?PromotionDTO
    {
        $merchant = $card['where'] ?? null;
        $title = $card['title'] ?? null;

        if (! is_string($merchant) || $merchant === '' || ! is_string($title) || $title === '') {
            return null;
        }

        $mapCategoryId = $card['categories_whitelist']['categories'][0]['map_category'] ?? null;
        $category = is_numeric($mapCategoryId) ? ($categories[(int) $mapCategoryId] ?? null) : null;

        $description = $card['short_description'] ?? null;
        $description = is_string($description) && $description !== '' ? $description : null;
        $externalId = $card['promo_id'] ?? $card['id'] ?? null;
        $iconUrl = $card['content']['image']['primary_image'] ?? null;
        $discountPercentage = $this->extractPercentage($title);
        $cashbackPercentage = null;
        $installments = null;
        $minimumPurchase = null;
        $reimbursementCap = null;
        $paymentMethods = $this->normalizePaymentMethods($card['debit_list'] ?? null, $card['credit_list'] ?? null);

        $slug = $card['slug'] ?? null;
        $detail = is_string($slug) && $slug !== '' ? $this->fetchDetailBundle($slug) : null;

        if ($detail !== null) {
            $enrichedDescription = $this->composeDescriptionFromDetail($detail);
            $description = $enrichedDescription ?? $description;

            $triggerParams = is_array($detail['trigger_params'] ?? null) ? $detail['trigger_params'] : [];

            $minimumPurchase = is_numeric($triggerParams['min_amount'] ?? null) ? (float) $triggerParams['min_amount'] : null;
            $reimbursementCap = $this->resolveReimbursementCap($triggerParams);

            $detailInstallments = is_array($triggerParams['installments'] ?? null)
                ? array_filter($triggerParams['installments'], 'is_numeric')
                : [];
            $installments = $detailInstallments !== [] ? (int) max($detailInstallments) : null;

            if ($discountPercentage !== null && str_contains(mb_strtolower((string) ($triggerParams['discount_mode'] ?? '')), 'cashback')) {
                $cashbackPercentage = $discountPercentage;
                $discountPercentage = null;
            }

            $detailPaymentMethods = $this->normalizePaymentMethods($triggerParams['debit_list'] ?? null, $triggerParams['credit_list'] ?? null);
            $paymentMethods = $detailPaymentMethods !== [] ? $detailPaymentMethods : $paymentMethods;
        }

        return new PromotionDTO(
            walletSlug: $this->walletSlug(),
            merchantName: $merchant,
            title: $title,
            merchantIconUrl: is_string($iconUrl) && $iconUrl !== '' ? $iconUrl : null,
            category: $category,
            description: $description,
            discountPercentage: $discountPercentage,
            cashbackPercentage: $cashbackPercentage,
            installments: $installments,
            reimbursementCap: $reimbursementCap,
            minimumPurchase: $minimumPurchase,
            validDays: $this->decodeDaysOfWeek(is_string($card['days_of_week'] ?? null) ? $card['days_of_week'] : null),
            startDate: $this->parseIsoDate(is_string($card['start_date'] ?? null) ? $card['start_date'] : null),
            endDate: $this->parseIsoDate(is_string($card['stop_date'] ?? null) ? $card['stop_date'] : null),
            externalId: is_string($externalId) ? $externalId : null,
            paymentMethods: $paymentMethods,
            rawPayload: $card,
        );
    }

    /**
     * Stage 2 enrichment for a single promo. Never throws — any failure
     * (network, unexpected shape, undecodable payload) yields `null`, and
     * the caller simply keeps the listing-only fields.
     *
     * @return array<string, mixed>|null
     */
    private function fetchDetailBundle(string $slug): ?array
    {
        try {
            $html = $this->http()->get(self::DETAIL_URL.$slug)->throw()->body();
        } catch (Throwable) {
            return null;
        }

        return $this->extractPromotionBundle($html);
    }

    /**
     * The detail page is a Next.js App Router page: its data arrives as
     * React Server Component payloads, each a JSON-escaped string inside
     * `self.__next_f.push([1, "..."])`. One of those strings, once properly
     * unescaped as a JSON string (not naive backslash-stripping, which
     * mangles `<`-style escapes), contains a JS object literal with
     * `trigger_params` and `sections` keys sitting alongside (not inside)
     * a `promotion` key — so each is pulled out independently by name
     * rather than assuming a common enclosing object to decode as a whole.
     *
     * @return array{trigger_params: array<string, mixed>, sections: array<string, mixed>}|null
     */
    private function extractPromotionBundle(string $html): ?array
    {
        if (preg_match_all('/self\.__next_f\.push\(\[1,"(.*?)"\]\)/s', $html, $matches) === false) {
            return null;
        }

        foreach ($matches[1] as $rawChunk) {
            $decoded = json_decode('"'.$rawChunk.'"');

            if (! is_string($decoded)) {
                continue;
            }

            $triggerParamsJson = $this->extractBalancedJsonValue($decoded, 'trigger_params');

            if ($triggerParamsJson === null) {
                continue;
            }

            $triggerParams = json_decode($triggerParamsJson, true);

            if (! is_array($triggerParams)) {
                continue;
            }

            $sectionsJson = $this->extractBalancedJsonValue($decoded, 'sections');
            $sections = $sectionsJson !== null ? json_decode($sectionsJson, true) : null;

            return [
                'trigger_params' => $triggerParams,
                'sections' => is_array($sections) ? $sections : [],
            ];
        }

        return null;
    }

    /**
     * Finds `"$key":{` in $haystack and returns the substring of its value,
     * scanning forward and counting brace depth (string- and
     * escape-aware) until it closes — without assuming anything about
     * what wraps it. Returns null if the key isn't found or never closes.
     */
    private function extractBalancedJsonValue(string $haystack, string $key): ?string
    {
        $needle = '"'.$key.'":{';
        $start = strpos($haystack, $needle);

        if ($start === false) {
            return null;
        }

        $openBracePos = $start + strlen($needle) - 1;
        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($haystack);

        for ($i = $openBracePos; $i < $length; $i++) {
            $char = $haystack[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($haystack, $openBracePos, $i - $openBracePos + 1);
                }
            }
        }

        return null;
    }

    /**
     * The detail bundle's `sections.body.contents` holds a plain-language
     * usage FAQ ("¿Cómo puedo acceder al beneficio?", "¿Es acumulable?",
     * etc.) — far more useful to a user than the listing's
     * `short_description` internal campaign code.
     *
     * Some promos (confirmed live: ~5% of MODO's catalog) don't inline this
     * text at all — instead of a string, `description` is itself a bare
     * Flight back-reference placeholder like `"$24"`, pointing at a
     * separately-numbered chunk this method never resolves (see the class
     * docblock). Left unguarded, that placeholder was landing verbatim in
     * the database as if it were real content. Rejected here instead.
     *
     * @param  array<string, mixed>  $bundle
     */
    private function composeDescriptionFromDetail(array $bundle): ?string
    {
        $contents = $bundle['sections']['body']['contents'] ?? null;

        if (! is_array($contents)) {
            return null;
        }

        foreach ($contents as $content) {
            if (! is_array($content) || ($content['content_type'] ?? null) !== 'text') {
                continue;
            }

            $html = $content['description'] ?? null;

            if (is_string($html) && preg_match('/^\$\d+$/', $html) === 1) {
                continue;
            }

            $text = is_string($html) ? $this->htmlBlockToPlainText($html) : '';

            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    private function htmlBlockToPlainText(string $html): string
    {
        $html = str_ireplace(['<br>', '<br/>', '<br />', '</p>'], "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\n{2,}/', "\n", $text) ?? $text;
        $text = implode("\n", array_filter(array_map('trim', explode("\n", $text)), fn ($line) => $line !== ''));

        return trim($text);
    }

    /**
     * @param  array<string, mixed>  $triggerParams
     */
    private function resolveReimbursementCap(array $triggerParams): ?float
    {
        $candidates = array_filter([
            $triggerParams['cap_transaction'] ?? null,
            $triggerParams['cap_amount_period'] ?? null,
            $triggerParams['cap_promotion'] ?? null,
            $triggerParams['cap_amount'] ?? null,
        ], fn ($value) => is_numeric($value) && (float) $value > 0);

        return $candidates === [] ? null : (float) max($candidates);
    }

    private function extractPercentage(string $title): ?float
    {
        if (! preg_match('/(\d+(?:[.,]\d+)?)\s*%/', $title, $matches)) {
            return null;
        }

        return (float) str_replace(',', '.', $matches[1]);
    }

    /**
     * @return string[]
     */
    private function decodeDaysOfWeek(?string $code): array
    {
        if ($code === null || $code === '') {
            return [];
        }

        $days = [];

        foreach (str_split($code) as $letter) {
            if (isset(self::DAY_LETTERS[$letter])) {
                $days[] = self::DAY_LETTERS[$letter];
            }
        }

        return $days;
    }

    /**
     * @return string[]
     */
    private function normalizePaymentMethods(mixed $debit, mixed $credit): array
    {
        $methods = array_merge(
            is_array($debit) ? $debit : [],
            is_array($credit) ? $credit : [],
        );

        $labels = array_map(
            fn ($method) => is_string($method) ? ucwords(str_replace('_', ' ', $method)) : null,
            $methods,
        );

        return array_values(array_unique(array_filter($labels, fn ($label) => is_string($label) && $label !== '')));
    }
}
