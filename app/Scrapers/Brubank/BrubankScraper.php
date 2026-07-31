<?php

namespace App\Scrapers\Brubank;

use App\Contracts\Scrapers\WalletScraperInterface;
use App\DTOs\PromotionDTO;
use App\Scrapers\Concerns\MakesHttpRequests;
use App\Scrapers\Concerns\ParsesForeignDates;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Throwable;

/**
 * brubank.com/beneficios is a static Webflow page (no API, everything is
 * already in the initial HTML) listing the same set of merchant benefits
 * three times over — once per subscription plan (Ultra/Plus/One) — each
 * copy with its own percentage/reimbursement cap/valid days for that tier.
 * Every card links to a help.brubank.com (Intercom help center) article
 * whose own `__NEXT_DATA__` carries the full legal text and, often, an
 * explicit validity date range — fetched once per unique card to capture
 * `terms` and, best-effort, `startDate`/`endDate`.
 */
class BrubankScraper implements WalletScraperInterface
{
    use MakesHttpRequests;
    use ParsesForeignDates;

    private const string URL = 'https://www.brubank.com/beneficios';

    /**
     * @var array<string, string>
     */
    private const array TIER_BY_CLASS_SUFFIX = [
        'card-promo-dark' => 'Ultra',
        'card-promo-purple' => 'Plus',
        'card-promo-white' => 'One',
    ];

    /**
     * @var string[]
     */
    private const array DAY_ORDER = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

    /**
     * @var array<string, string>
     */
    private const array DAY_PATTERNS = [
        'Lunes' => '/lunes/iu',
        'Martes' => '/martes/iu',
        'Miércoles' => '/mi[eé]rcoles/iu',
        'Jueves' => '/jueves/iu',
        'Viernes' => '/viernes/iu',
        'Sábado' => '/s[aá]bados?/iu',
        'Domingo' => '/domingos?/iu',
    ];

    public function walletSlug(): string
    {
        return 'brubank';
    }

    public function scrape(): iterable
    {
        $html = $this->http()->get(self::URL)->throw()->body();

        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $xpath = new DOMXPath($document);
        $cards = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " special-card-carousel ")]');

        $articleCache = [];

        foreach ($cards as $card) {
            if (! $card instanceof DOMElement) {
                continue;
            }

            $dto = $this->cardToDto($xpath, $card, $articleCache);

            if ($dto !== null) {
                yield $dto;
            }
        }
    }

    /**
     * @param  array<string, array{description: ?string, startDate: ?DateTimeInterface, endDate: ?DateTimeInterface, terms: ?string}>  $articleCache
     */
    private function cardToDto(DOMXPath $xpath, DOMElement $card, array &$articleCache): ?PromotionDTO
    {
        $tier = $this->tierFromClass($card->getAttribute('class'));

        if ($tier === null) {
            return null;
        }

        $heading = $xpath->query('.//h4', $card)->item(0);

        if ($heading === null) {
            return null;
        }

        $strong = $xpath->query('.//strong', $heading)->item(0);
        $incentiveText = $strong !== null ? $this->cleanText($strong->textContent) : '';

        if ($incentiveText === '') {
            return null;
        }

        $merchantName = $this->cleanText(str_replace($strong->textContent, '', $heading->textContent));

        if ($merchantName === '') {
            return null;
        }

        $paragraph = $xpath->query('.//p', $card)->item(0);
        $topeNode = $paragraph !== null ? $xpath->query('.//span[contains(@class,"span-tope")]', $paragraph)->item(0) : null;
        $linkNode = $paragraph !== null ? $xpath->query('.//a', $paragraph)->item(0) : null;

        $daysText = '';

        if ($paragraph !== null) {
            $daysText = $paragraph->textContent;
            $daysText = $topeNode !== null ? str_replace($topeNode->textContent, '', $daysText) : $daysText;
            $daysText = $linkNode !== null ? str_replace($linkNode->textContent, '', $daysText) : $daysText;
            $daysText = $this->cleanText($daysText);
        }

        $url = $linkNode instanceof DOMElement ? $linkNode->getAttribute('href') : null;
        $iconUrl = $xpath->query('.//img/@src', $card)->item(0)?->textContent;

        $discountPercentage = null;
        $cashbackPercentage = null;

        if (preg_match('/(\d+(?:[.,]\d+)?)\s*%\s*de\s*reintegro/iu', $incentiveText, $matches)) {
            $cashbackPercentage = (float) str_replace(',', '.', $matches[1]);
        } elseif (preg_match('/(\d+(?:[.,]\d+)?)\s*%\s*de\s*descuento/iu', $incentiveText, $matches)) {
            $discountPercentage = (float) str_replace(',', '.', $matches[1]);
        }

        $article = $url !== null && $url !== '' ? $this->fetchArticle($url, $articleCache) : null;
        $description = trim('Plan '.$tier.'.'.($article['description'] ?? null ? ' '.$article['description'] : ''));

        return new PromotionDTO(
            walletSlug: $this->walletSlug(),
            merchantName: $merchantName,
            title: $incentiveText,
            merchantIconUrl: is_string($iconUrl) && $iconUrl !== '' ? $iconUrl : null,
            category: null,
            description: $description,
            discountPercentage: $discountPercentage,
            cashbackPercentage: $cashbackPercentage,
            installments: $this->parseInstallments($incentiveText),
            reimbursementCap: $this->parseCurrencyAmount($topeNode?->textContent),
            validDays: $this->parseValidDays($daysText),
            startDate: $article['startDate'] ?? null,
            endDate: $article['endDate'] ?? null,
            terms: $article['terms'] ?? null,
            url: $url !== '' ? $url : null,
            externalId: $this->externalIdFromUrl($url),
            rawPayload: [
                'tier' => $tier,
                'incentive' => $incentiveText,
                'merchant' => $merchantName,
                'tope' => $topeNode?->textContent,
                'days' => $daysText,
                'url' => $url,
            ],
        );
    }

    private function tierFromClass(string $class): ?string
    {
        foreach (self::TIER_BY_CLASS_SUFFIX as $suffix => $tier) {
            if (str_contains($class, $suffix)) {
                return $tier;
            }
        }

        return null;
    }

    private function parseInstallments(string $text): ?int
    {
        if (! str_contains(mb_strtolower($text), 'cuota')) {
            return null;
        }

        preg_match_all('/\d+/', $text, $matches);

        return $matches[0] === [] ? null : (int) max($matches[0]);
    }

    private function parseCurrencyAmount(?string $value): ?float
    {
        if ($value === null || ! preg_match('/\$\s*([\d.,]+)/', $value, $matches)) {
            return null;
        }

        $normalized = str_replace('.', '', $matches[1]);
        $normalized = str_replace(',', '.', $normalized);

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    /**
     * @return string[]
     */
    private function parseValidDays(string $text): array
    {
        $normalized = mb_strtolower($text);

        if ($normalized === '') {
            return [];
        }

        if (str_contains($normalized, 'todos los días') || str_contains($normalized, 'todos los dias')) {
            return self::DAY_ORDER;
        }

        if (preg_match('/(lunes|martes|mi[eé]rcoles|jueves|viernes|s[aá]bados?|domingos?)\s+a\s+(lunes|martes|mi[eé]rcoles|jueves|viernes|s[aá]bados?|domingos?)/iu', $text, $matches)) {
            $from = $this->canonicalDay($matches[1]);
            $to = $this->canonicalDay($matches[2]);

            if ($from !== null && $to !== null) {
                return $this->expandDayRange($from, $to);
            }
        }

        $days = [];

        foreach (self::DAY_PATTERNS as $canonical => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $days[] = $canonical;
            }
        }

        return $days;
    }

    private function canonicalDay(string $token): ?string
    {
        foreach (self::DAY_PATTERNS as $canonical => $pattern) {
            if (preg_match($pattern, $token) === 1) {
                return $canonical;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function expandDayRange(string $from, string $to): array
    {
        $fromIndex = array_search($from, self::DAY_ORDER, true);
        $toIndex = array_search($to, self::DAY_ORDER, true);

        if ($fromIndex === false || $toIndex === false) {
            return array_values(array_unique([$from, $to]));
        }

        $result = [];
        $i = $fromIndex;

        while (true) {
            $result[] = self::DAY_ORDER[$i];

            if ($i === $toIndex) {
                break;
            }

            $i = ($i + 1) % 7;
        }

        return $result;
    }

    private function cleanText(string $text): string
    {
        $text = preg_replace('/[\x{200D}\x{00A0}]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function externalIdFromUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return $path === '' ? null : $path;
    }

    /**
     * @param  array<string, array{description: ?string, startDate: ?DateTimeInterface, endDate: ?DateTimeInterface, terms: ?string}>  $cache
     * @return array{description: ?string, startDate: ?DateTimeInterface, endDate: ?DateTimeInterface, terms: ?string}
     */
    private function fetchArticle(string $url, array &$cache): array
    {
        if (array_key_exists($url, $cache)) {
            return $cache[$url];
        }

        $empty = ['description' => null, 'startDate' => null, 'endDate' => null, 'terms' => null];

        try {
            $html = $this->http()->get($url)->throw()->body();
        } catch (Throwable) {
            return $cache[$url] = $empty;
        }

        if (! preg_match('/<script id="__NEXT_DATA__"[^>]*>(.*?)<\/script>/s', $html, $matches)) {
            return $cache[$url] = $empty;
        }

        $data = json_decode($matches[1], true);
        $article = $data['props']['pageProps']['articleContent'] ?? null;

        if (! is_array($article)) {
            return $cache[$url] = $empty;
        }

        $blocks = is_array($article['blocks'] ?? null) ? $article['blocks'] : [];
        $terms = $this->blocksToPlainText($blocks);
        $description = is_string($article['description'] ?? null) ? trim($article['description']) : '';

        [$startDate, $endDate] = $this->extractDateRange($description);

        return $cache[$url] = [
            'description' => $description === '' ? null : $description,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'terms' => $terms === '' ? null : $terms,
        ];
    }

    /**
     * @param  array<int, mixed>  $blocks
     */
    private function blocksToPlainText(array $blocks): string
    {
        $lines = [];

        foreach ($blocks as $block) {
            if (! is_array($block) || ! is_string($block['text'] ?? null)) {
                continue;
            }

            $text = trim(html_entity_decode(strip_tags($block['text']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($text !== '') {
                $lines[] = $text;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Only the reliable numeric `D/M/Y ... D/M/Y` phrasing (e.g. "entre el
     * 01/07/26 hasta el 31/07/26") is parsed here. Articles also use
     * free-form Spanish prose ("desde el 20 de julio y el 30 de septiembre
     * de 2026") for the same purpose, but that format's first date omits its
     * year, and guessing it would risk a silently wrong date — left null
     * instead, consistent with the rest of `ParsesForeignDates`.
     *
     * @return array{0: ?DateTimeInterface, 1: ?DateTimeInterface}
     */
    private function extractDateRange(string $text): array
    {
        if ($text === '' || ! preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{2,4})\D+(?:hasta|y)\D*?(\d{1,2})\/(\d{1,2})\/(\d{2,4})/u', $text, $matches)) {
            return [null, null];
        }

        return [
            $this->buildDate((int) $matches[1], (int) $matches[2], (int) $matches[3]),
            $this->buildDate((int) $matches[4], (int) $matches[5], (int) $matches[6]),
        ];
    }

    private function buildDate(int $day, int $month, int $year): ?DateTimeInterface
    {
        if ($year < 100) {
            $year += 2000;
        }

        try {
            return CarbonImmutable::create($year, $month, $day)?->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
