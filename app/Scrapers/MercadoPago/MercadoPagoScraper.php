<?php

namespace App\Scrapers\MercadoPago;

use App\Contracts\Scrapers\WalletScraperInterface;
use App\DTOs\PromotionDTO;
use App\Scrapers\Concerns\MakesHttpRequests;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * promociones.mercadopago.com.ar is plain WordPress/Elementor HTML (theme
 * "kiyo") — every promotion is already in the initial page, no API to call.
 * Parsed with the native DOMDocument/DOMXPath instead of a Composer
 * dependency (no HTML parser package is installed in this project).
 */
class MercadoPagoScraper implements WalletScraperInterface
{
    use MakesHttpRequests;

    private const string URL = 'https://promociones.mercadopago.com.ar/?_sf_ppp=100';

    /**
     * @var array<string, int>
     */
    private const array SPANISH_MONTHS = [
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4,
        'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8,
        'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10,
        'noviembre' => 11, 'diciembre' => 12,
    ];

    public function walletSlug(): string
    {
        return 'mercado_pago';
    }

    public function scrape(): iterable
    {
        $html = $this->http()->get(self::URL)->throw()->body();

        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $xpath = new DOMXPath($document);
        $cards = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " kiyo__cards--col ")]');

        foreach ($cards as $card) {
            if (! $card instanceof DOMElement) {
                continue;
            }

            $dto = $this->cardToDto($xpath, $card);

            if ($dto !== null) {
                yield $dto;
            }
        }
    }

    private function cardToDto(DOMXPath $xpath, DOMElement $card): ?PromotionDTO
    {
        $merchant = $this->text($xpath, './/div[contains(concat(" ", normalize-space(@class), " "), " kiyo__data--details-logo ")]//h3', $card);

        if ($merchant === null) {
            return null;
        }

        $badges = [];
        foreach ($xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " kiyo__cards--pillsgroup ")]//span', $card) as $span) {
            $badges[] = trim($span->textContent);
        }

        $description = $this->text($xpath, './/div[contains(concat(" ", normalize-space(@class), " "), " kiyo__data--details-row1 ")]//p', $card);
        $legal = $this->text($xpath, './/div[contains(concat(" ", normalize-space(@class), " "), " kiyo__data--details-row2 ")]//small', $card);
        $url = $xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " kiyo__data--details-btn ")]//a/@href', $card)->item(0)?->textContent;
        $iconUrl = $xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " kiyo__data--details-logo-img ")]//img/@src', $card)->item(0)?->textContent;

        [$discountPercentage, $cashbackPercentage, $installments] = $this->parseBadges($badges);
        [$startDate, $endDate] = $this->parseValidityRange($legal);

        return new PromotionDTO(
            walletSlug: $this->walletSlug(),
            merchantName: $merchant,
            title: $badges[0] ?? $merchant,
            merchantIconUrl: $iconUrl,
            category: null,
            description: $description,
            discountPercentage: $discountPercentage,
            cashbackPercentage: $cashbackPercentage,
            installments: $installments,
            startDate: $startDate,
            endDate: $endDate,
            terms: $legal,
            url: $url,
            externalId: $this->externalIdFromUrl($url),
            rawPayload: [
                'merchant' => $merchant,
                'badges' => $badges,
                'description' => $description,
                'legal' => $legal,
                'url' => $url,
                'icon_url' => $iconUrl,
            ],
        );
    }

    private function text(DOMXPath $xpath, string $query, DOMElement $context): ?string
    {
        $node = $xpath->query($query, $context)?->item(0);

        if ($node === null) {
            return null;
        }

        $text = trim(preg_replace('/\s+/', ' ', $node->textContent));

        return $text === '' ? null : $text;
    }

    /**
     * @param  string[]  $badges
     * @return array{0: ?float, 1: ?float, 2: ?int}
     */
    private function parseBadges(array $badges): array
    {
        $discountPercentage = null;
        $cashbackPercentage = null;
        $installments = null;

        foreach ($badges as $badge) {
            if ($cashbackPercentage === null && preg_match('/(\d+(?:[.,]\d+)?)\s*%.*(?:reintegro|cashback)/iu', $badge, $matches)) {
                $cashbackPercentage = (float) str_replace(',', '.', $matches[1]);
            } elseif ($discountPercentage === null && preg_match('/(\d+(?:[.,]\d+)?)\s*%/u', $badge, $matches)) {
                $discountPercentage = (float) str_replace(',', '.', $matches[1]);
            }

            if ($installments === null && preg_match('/(\d+)\s*cuotas/iu', $badge, $matches)) {
                $installments = (int) $matches[1];
            }
        }

        return [$discountPercentage, $cashbackPercentage, $installments];
    }

    /**
     * Parses "Válido del 11 al 17 de mayo" into a start/end date pair. The
     * source never prints a year, so the current year is assumed.
     *
     * @return array{0: ?\DateTimeInterface, 1: ?\DateTimeInterface}
     */
    private function parseValidityRange(?string $legal): array
    {
        if ($legal === null || ! preg_match('/(\d{1,2})\s+al\s+(\d{1,2})\s+de\s+([a-záéíóúñ]+)/iu', $legal, $matches)) {
            return [null, null];
        }

        $month = self::SPANISH_MONTHS[mb_strtolower($matches[3])] ?? null;

        if ($month === null) {
            return [null, null];
        }

        try {
            $year = Carbon::now()->year;

            return [
                Carbon::create($year, $month, (int) $matches[1])?->startOfDay(),
                Carbon::create($year, $month, (int) $matches[2])?->startOfDay(),
            ];
        } catch (Throwable) {
            return [null, null];
        }
    }

    private function externalIdFromUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return $path === '' ? null : $path;
    }
}
