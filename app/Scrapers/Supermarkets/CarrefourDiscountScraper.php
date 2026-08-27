<?php

namespace App\Scrapers\Supermarkets;

use App\Actions\Scraping\ResolveWalletFromBankNameAction;
use App\Contracts\Scrapers\MerchantScraperInterface;
use App\DTOs\PromotionDTO;
use App\Scrapers\Concerns\ConvertsIsoWeekDays;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Str;
use Spatie\Browsershot\Browsershot;

/**
 * Carrefour's "descuentos-bancarios" page is a client-rendered SPA — a
 * plain HTTP GET returns an empty shell, so this is the one supermarket
 * scraper that needs a real headless browser (spatie/browsershot, added
 * specifically for this). Ported from proyecto-scrapping-super's own
 * CarrefourDiscountScraper, confirmed live against the same page.
 *
 * Unlike every other supermarket scraper, Carrefour's own cards never
 * carry a clean, structured bank label — the bank has to be inferred from
 * the card's own free text (title/description/legal) and its image
 * filenames (`alt` attributes), confirmed live against all 31 cards
 * currently on the page. `detectWalletName()` holds the ordered,
 * first-match-wins keyword list this discovered; a card matching none of
 * them (a "Mi Carrefour"/Anses/age-based eligibility card with no actual
 * payment method tied to it, or an explicit "todos los medios de pago"
 * card) is dropped rather than guessed.
 *
 * Needs a Chrome/Chromium binary Puppeteer can launch — see
 * config/services.php's own `browsershot` entry for why `npm install`
 * alone doesn't provide one in this project.
 */
class CarrefourDiscountScraper implements MerchantScraperInterface
{
    use ConvertsIsoWeekDays;

    private const string SOURCE_URL = 'https://www.carrefour.com.ar/descuentos-bancarios';

    private const string CARD_CLASS = 'valtech-carrefourar-bank-promotions-0-x-cardBox';

    private const string TITLE_CLASS = 'valtech-carrefourar-bank-promotions-0-x-ColRightTittle';

    private const string DESCRIPTION_CLASS = 'valtech-carrefourar-bank-promotions-0-x-ColRightText';

    private const string DATE_CLASS = 'valtech-carrefourar-bank-promotions-0-x-dateText';

    private const string PERCENTAGE_CLASS = 'valtech-carrefourar-bank-promotions-0-x-ColLeftPercentage';

    private const string PERCENTAGE_SYMBOL_CLASS = 'valtech-carrefourar-bank-promotions-0-x-ColLeftPercentageSymbol';

    private const string LEGAL_CLASS = 'valtech-carrefourar-bank-promotions-0-x-legalContent';

    private const string IMAGES_CONTAINER_CLASS = 'valtech-carrefourar-bank-promotions-0-x-ColRight__CardImagesContainer';

    /**
     * Ordered, first-match-wins keyword -> canonical wallet name, checked
     * against the card's own title/description/legal text *and* its image
     * `alt` filenames combined. Order matters: a named bank is checked
     * before a generic payment-channel mention the same card might also
     * carry (a MODO-channel card whose own logo file names its issuing
     * bank, "bna-logo.png", resolves to Banco Nación — not bare "Modo" —
     * same "the underlying bank matters more than the MODO channel" idea
     * as `Merchant::stripModoSuffix()`). "Patagonia" alone (not "Banco
     * Patagonia") because several cards only carry it via a filename like
     * "Promo-bancaria_patagonia.webp" ("bancaria", not "banco").
     *
     * @var array<string, string>
     */
    private const array KEYWORD_WALLETS = [
        '/carrefour[\s_-]*banco/iu' => 'Carrefour Banco',
        '/\bbna\b/iu' => 'Banco Nación',
        '/patagonia/iu' => 'Banco Patagonia',
        '/cuenta[\s_-]*dni/iu' => 'Cuenta DNI',
        '/mercado[\s_-]*pago/iu' => 'Mercado Pago',
        '/\bmodo\b/iu' => 'MODO',
    ];

    public function __construct(
        private readonly ResolveWalletFromBankNameAction $resolveWallet,
    ) {}

    public function merchantName(): string
    {
        return 'Carrefour';
    }

    public function scrape(): iterable
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$this->renderHtml(), LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        foreach ($this->queryClass($xpath, self::CARD_CLASS) as $card) {
            $dto = $this->cardToDto($xpath, $card);

            if ($dto !== null) {
                yield $dto;
            }
        }
    }

    /**
     * Overridden in tests to return a fixture instead of actually
     * launching a browser.
     */
    protected function renderHtml(): string
    {
        $browsershot = Browsershot::url(self::SOURCE_URL)
            ->noSandbox()
            ->delay(6000)
            ->timeout(90);

        if ($nodeBinary = config('services.browsershot.node_binary')) {
            $browsershot->setNodeBinary($nodeBinary);
        }

        if ($chromePath = config('services.browsershot.chrome_path')) {
            $browsershot->setChromePath($chromePath);
        }

        return $browsershot->bodyHtml();
    }

    private function cardToDto(DOMXPath $xpath, DOMElement $card): ?PromotionDTO
    {
        $title = $this->textOrNull($xpath, self::TITLE_CLASS, $card);
        $description = $this->textOrNull($xpath, self::DESCRIPTION_CLASS, $card);
        $dateText = $this->textOrNull($xpath, self::DATE_CLASS, $card) ?? '';
        $legalText = $this->textOrNull($xpath, self::LEGAL_CLASS, $card);
        $percentage = $this->textOrNull($xpath, self::PERCENTAGE_CLASS, $card);
        $symbol = $this->textOrNull($xpath, self::PERCENTAGE_SYMBOL_CLASS, $card);
        $imageAlts = $this->imageAlts($xpath, $card);

        $haystack = implode(' ', array_filter([$title, $description, $legalText, implode(' ', $imageAlts)]));
        $walletName = $this->detectWalletName($haystack);

        if ($walletName === null) {
            return null;
        }

        $wallet = $this->resolveWallet->handle($walletName);

        if ($wallet === null) {
            return null;
        }

        return new PromotionDTO(
            walletSlug: $wallet->slug,
            merchantName: $this->merchantName(),
            title: $title ?? $dateText,
            category: null,
            description: $description,
            discountPercentage: $symbol === '%' && $percentage !== null ? (float) $percentage : null,
            installments: $this->installmentsValue($percentage, $symbol),
            validDays: $this->resolveValidDaysFromIso($this->parseIsoWeekDaysFromSpanishText($dateText)),
            terms: $legalText,
            url: self::SOURCE_URL,
            externalId: sha1(implode('|', ['carrefour', $dateText, $title ?? '', $description ?? ''])),
            rawPayload: [
                'title' => $title,
                'description' => $description,
                'date_text' => $dateText,
                'legal' => $legalText,
                'percentage' => $percentage,
                'symbol' => $symbol,
                'image_alts' => $imageAlts,
            ],
        );
    }

    private function installmentsValue(?string $percentage, ?string $symbol): ?int
    {
        if ($percentage === null || $symbol === null || ! str_contains(mb_strtolower($symbol), 'cuota')) {
            return null;
        }

        return (int) $percentage;
    }

    /**
     * An explicit "applies to every payment method" card isn't
     * attributable to any one wallet, regardless of which logos happen to
     * appear in its own image filenames — confirmed live (one card lists
     * "Todos los Medios de Pago" while its images include several
     * unrelated bank/wallet logos side by side).
     */
    private function detectWalletName(string $haystack): ?string
    {
        $normalized = Str::ascii($haystack);

        if (preg_match('/todos los medios de pago/iu', $normalized) === 1) {
            return null;
        }

        foreach (self::KEYWORD_WALLETS as $pattern => $walletName) {
            if (preg_match($pattern, $normalized) === 1) {
                return $walletName;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function imageAlts(DOMXPath $xpath, DOMElement $card): array
    {
        $container = $this->queryClass($xpath, self::IMAGES_CONTAINER_CLASS, $card)->item(0);

        if ($container === null) {
            return [];
        }

        $alts = [];

        foreach ($xpath->query('.//img', $container) as $img) {
            $alts[] = $img->getAttribute('alt');
        }

        return $alts;
    }

    private function textOrNull(DOMXPath $xpath, string $class, DOMElement $context): ?string
    {
        $node = $this->queryClass($xpath, $class, $context)->item(0);
        $text = $node !== null ? trim($node->textContent) : '';

        return $text !== '' ? $text : null;
    }

    /**
     * XPath has no native class-list selector, so this builds the usual
     * `contains(concat(" ", @class, " "), " x ")` guard by hand, relative
     * to `$context`.
     *
     * @return \DOMNodeList<DOMElement>
     */
    private function queryClass(DOMXPath $xpath, string $class, ?DOMElement $context = null): \DOMNodeList
    {
        $classGuard = "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";

        return $context !== null
            ? $xpath->query(".//*[{$classGuard}]", $context)
            : $xpath->query("//*[{$classGuard}]");
    }
}
