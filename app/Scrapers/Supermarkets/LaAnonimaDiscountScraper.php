<?php

namespace App\Scrapers\Supermarkets;

use App\Actions\Scraping\ResolveWalletFromBankNameAction;
use App\Contracts\Scrapers\MerchantScraperInterface;
use App\DTOs\PromotionDTO;
use App\Scrapers\Concerns\ConvertsIsoWeekDays;
use App\Scrapers\Concerns\MakesHttpRequests;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Unlike Carrefour, La Anónima's promotions page is plain server-rendered
 * HTML: every bank's discount card already sits in the DOM (hidden via CSS
 * and toggled by a bank-filter click), so a normal HTTP GET is enough — no
 * headless browser required. It only needs a browser-like User-Agent,
 * otherwise the site's bot protection returns a 403. Ported from
 * proyecto-scrapping-super's own LaAnonimaDiscountScraper, confirmed live
 * against the same page — same `#filterBancos .banco-card` /
 * `#banco .promo-card` structure, same `data-day` numbering (1=Lunes ...
 * 7=Domingo, matching `ConvertsIsoWeekDays` exactly).
 *
 * Uses native DOMDocument/DOMXPath rather than symfony/dom-crawler (the
 * original project's choice) to avoid a second unapproved Composer
 * dependency beyond spatie/browsershot — matches this app's own existing
 * convention (MercadoPagoScraper, BrubankScraper).
 */
class LaAnonimaDiscountScraper implements MerchantScraperInterface
{
    use ConvertsIsoWeekDays;
    use MakesHttpRequests;

    private const string SOURCE_URL = 'https://www.laanonima.com.ar/empresa/promociones-y-descuentos';

    public function __construct(
        private readonly ResolveWalletFromBankNameAction $resolveWallet,
    ) {}

    public function merchantName(): string
    {
        return 'La Anónima';
    }

    public function scrape(): iterable
    {
        $dtos = $this->parseHtml($this->fetchHtml());

        // The site's bot-detection occasionally serves a 200 response with
        // no bank tiles/promo cards at all; retrying the whole fetch once
        // fixes it in practice — confirmed on the original scraper this
        // one was ported from.
        if ($dtos === []) {
            $dtos = $this->parseHtml($this->fetchHtml());
        }

        yield from $dtos;
    }

    private function fetchHtml(): string
    {
        return $this->http()->get(self::SOURCE_URL)->throw()->body();
    }

    /**
     * @return list<PromotionDTO>
     */
    private function parseHtml(string $html): array
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        // Without an explicit encoding hint, loadHTML() mis-detects this
        // UTF-8 page as Latin-1 and mangles every accented bank name (same
        // fix MercadoPagoScraper/BrubankScraper already use).
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $banks = $this->bankInfoById($xpath);

        if ($banks === []) {
            return [];
        }

        $dtos = [];

        foreach ($this->queryClass($xpath, 'promo-card', 'banco') as $card) {
            foreach ($this->cardToDtos($card, $xpath, $banks) as $dto) {
                $dtos[] = $dto;
            }
        }

        return $dtos;
    }

    /**
     * @return array<string, string> bank id => raw bank label
     */
    private function bankInfoById(DOMXPath $xpath): array
    {
        $banks = [];

        foreach ($this->queryClass($xpath, 'banco-card', 'filterBancos') as $tile) {
            $id = $tile->getAttribute('data-id');
            $img = $xpath->query('.//img', $tile)->item(0);

            if ($id !== '' && $img instanceof DOMElement) {
                $name = trim($img->getAttribute('alt'));

                if ($name !== '') {
                    $banks[$id] = $name;
                }
            }
        }

        return $banks;
    }

    /**
     * A single promo card can apply to several banks (pipe-delimited
     * `data-bank`), so it yields one DTO per bank rather than a single
     * result — matches the source page's own filter behavior.
     *
     * @param  array<string, string>  $banks
     * @return list<PromotionDTO>
     */
    private function cardToDtos(DOMElement $card, DOMXPath $xpath, array $banks): array
    {
        // Not a plain array_filter(): "Banco Galicia" is bank id "0" on
        // this page's own filter, and PHP's default array_filter callback
        // treats the string "0" as falsy and silently drops it.
        $bankIds = array_filter(
            explode('|', $card->getAttribute('data-bank')),
            fn (string $id) => $id !== '',
        );

        if ($bankIds === []) {
            return [];
        }

        $isoDays = collect(explode('|', $card->getAttribute('data-day')))
            ->filter(fn (string $day) => $day !== '')
            ->map(fn (string $day) => (int) $day)
            ->unique()
            ->values()
            ->all();

        $titleNode = $xpath->query('.//h3//strong', $card)->item(0);
        $title = $titleNode !== null ? trim($titleNode->textContent) : '';

        $legalNode = $this->queryClass($xpath, 'promo-card-legal', null, $card)->item(0);
        $legalText = $legalNode !== null ? trim($legalNode->textContent) : null;
        $legalText = $legalText !== '' ? $legalText : null;

        $discountLabel = $this->extractDiscountLabel($title);
        $validDays = $this->resolveValidDaysFromIso($isoDays !== [] ? $isoDays : null);

        $dtos = [];

        foreach ($bankIds as $id) {
            if (! isset($banks[$id])) {
                continue;
            }

            $wallet = $this->resolveWallet->handle($banks[$id]);

            if ($wallet === null) {
                continue;
            }

            $dtos[] = new PromotionDTO(
                walletSlug: $wallet->slug,
                merchantName: $this->merchantName(),
                title: $title !== '' ? $title : trim(implode(' ', array_filter([$discountLabel, $wallet->name]))),
                category: null,
                discountPercentage: $this->percentageFromLabel($discountLabel),
                installments: $this->installmentsFromLabel($discountLabel),
                validDays: $validDays,
                terms: $legalText,
                url: self::SOURCE_URL.'#banco',
                externalId: sha1(implode('|', ['la-anonima', $id, $title, $legalText ?? ''])),
                rawPayload: [
                    'bank_id' => $id,
                    'bank_name' => $banks[$id],
                    'title' => $title,
                    'legal' => $legalText,
                    'days' => $isoDays,
                ],
            );
        }

        return $dtos;
    }

    private function extractDiscountLabel(string $title): ?string
    {
        if (preg_match('/(\d+(?:[.,]\d+)?\s*%)/', $title, $matches) === 1) {
            return str_replace(' ', '', $matches[1]);
        }

        if (preg_match('/(\d+)\s*cuotas/i', $title, $matches) === 1) {
            return "{$matches[1]} cuotas";
        }

        return null;
    }

    private function percentageFromLabel(?string $label): ?float
    {
        if ($label === null || ! str_ends_with($label, '%')) {
            return null;
        }

        return (float) str_replace([',', '%'], ['.', ''], $label);
    }

    private function installmentsFromLabel(?string $label): ?int
    {
        if ($label === null || ! preg_match('/^(\d+)\s*cuotas$/i', $label, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * Finds every descendant carrying `$class` in its class list, either
     * scoped to the (single) element with id `$withinId` (an absolute
     * query against the whole document) or relative to `$context` — XPath
     * has no native class-list selector, so this builds the usual
     * `contains(concat(" ", @class, " "), " x ")` guard by hand.
     *
     * @return \DOMNodeList<DOMElement>
     */
    private function queryClass(DOMXPath $xpath, string $class, ?string $withinId = null, ?DOMElement $context = null): \DOMNodeList
    {
        $classGuard = "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";

        if ($context !== null) {
            return $xpath->query(".//*[{$classGuard}]", $context);
        }

        $scope = $withinId !== null ? "//*[@id='{$withinId}']" : '';

        return $xpath->query("{$scope}//*[{$classGuard}]");
    }
}
