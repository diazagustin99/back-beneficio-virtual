<?php

namespace App\Scrapers\Supermarkets;

use App\Actions\Scraping\ResolveWalletFromBankNameAction;
use App\Contracts\Scrapers\MerchantScraperInterface;
use App\DTOs\PromotionDTO;
use App\Scrapers\Concerns\ConvertsIsoWeekDays;
use App\Scrapers\Concerns\MakesHttpRequests;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;

/**
 * ChangoMás (masonline.com.ar) is a VTEX store whose "Promociones bancarias"
 * page is powered by a small first-party GraphQL app
 * (valtech.gdn-banks-promotions) with two Automatic Persisted Queries —
 * GetBanks (id -> name lookup) and GetPromos (the actual discount
 * documents) — both public and callable directly by hash, no browser
 * rendering required. Ported from proyecto-scrapping-super's own
 * ChangoMasDiscountScraper, confirmed live against the same endpoint.
 *
 * Confirmed live: the feed's own bank catalog mixes real banks with card
 * networks, MODO/salary-account qualifier suffixes on otherwise-known
 * banks ("Banco Credicoop MODO", "ICBC_Sueldos"), the store's own loyalty
 * club ("MasClub"), and government/customer-segment labels ("Anses",
 * "Empleados Públicos") — see config/bank_wallet_aliases.php for how each
 * is resolved; ResolveWalletFromBankNameAction returns null for the
 * non-bank ones and this scraper just drops that one discount.
 */
class ChangoMasDiscountScraper implements MerchantScraperInterface
{
    use ConvertsIsoWeekDays;
    use MakesHttpRequests;

    private const string GRAPHQL_URL = 'https://www.masonline.com.ar/_v/public/graphql/v1';

    private const string ACCOUNT_NAME = 'masonlineprod';

    private const string SOURCE_URL = 'https://www.masonline.com.ar/promociones-bancarias';

    private const string GET_BANKS_HASH = '968d464317be357766de0e3beb313a55e0ebf7f45f2ef4a02c99fdf4ebca0876';

    private const string GET_PROMOS_HASH = '1a071ebc5dc407a3f65e687b0f4c0a3b8d12a0c45d8d11370075c3b2a505251c';

    /**
     * @var array<string, int>
     */
    private const array DAY_FIELDS = [
        'monday' => 1,
        'tuesday' => 2,
        'wednesday' => 3,
        'thursday' => 4,
        'friday' => 5,
        'saturday' => 6,
        'sunday' => 7,
    ];

    public function __construct(
        private readonly ResolveWalletFromBankNameAction $resolveWallet,
    ) {}

    public function merchantName(): string
    {
        return 'ChangoMás';
    }

    public function scrape(): iterable
    {
        $banks = $this->fetchBanks();

        foreach ($this->fetchActivePromos() as $promo) {
            $dto = $this->promoToDto($promo, $banks);

            if ($dto !== null) {
                yield $dto;
            }
        }
    }

    /**
     * @return array<string, string> bank id => raw bank label
     */
    private function fetchBanks(): array
    {
        $response = $this->queryPersisted('GetBanks', self::GET_BANKS_HASH, ['account' => self::ACCOUNT_NAME]);

        $banks = [];

        foreach ($response->json('data.documents') ?? [] as $doc) {
            $fields = $this->fieldsToArray($doc);
            $banks[$fields['id']] = $fields['name'];
        }

        return $banks;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function fetchActivePromos(): array
    {
        $now = now()->format('Y-m-d\TH:i:s');
        $where = "active=true AND ((active_from < {$now}) AND (active_to > {$now}))";

        $response = $this->queryPersisted('GetPromos', self::GET_PROMOS_HASH, [
            'where' => $where,
            'account' => self::ACCOUNT_NAME,
        ]);

        $promos = [];

        foreach ($response->json('data.documents') ?? [] as $doc) {
            $promos[] = $this->fieldsToArray($doc);
        }

        return $promos;
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function queryPersisted(string $operationName, string $hash, array $variables): Response
    {
        $extensions = json_encode([
            'persistedQuery' => [
                'version' => 1,
                'sha256Hash' => $hash,
                'sender' => 'valtech.gdn-banks-promotions@0.x',
                'provider' => 'vtex.store-graphql@2.x',
            ],
            'variables' => base64_encode(json_encode($variables)),
        ]);

        return $this->http()
            ->get(self::GRAPHQL_URL, [
                'workspace' => 'master',
                'maxAge' => 'short',
                'appsEtag' => 'remove',
                'domain' => 'store',
                'locale' => 'es-AR',
                'operationName' => $operationName,
                'variables' => '{}',
                'extensions' => $extensions,
            ])
            ->throw();
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, string>
     */
    private function fieldsToArray(array $doc): array
    {
        $fields = [];

        foreach ($doc['fields'] as $field) {
            $fields[$field['key']] = $field['value'];
        }

        return $fields;
    }

    /**
     * @param  array<string, string>  $promo
     * @param  array<string, string>  $banks
     */
    private function promoToDto(array $promo, array $banks): ?PromotionDTO
    {
        $bankLabel = trim($banks[$promo['idBank'] ?? ''] ?? '');

        if ($bankLabel === '') {
            return null;
        }

        $wallet = $this->resolveWallet->handle($bankLabel);

        if ($wallet === null) {
            return null;
        }

        [$discountPercentage, $cashbackPercentage, $installments, $discountLabel] = $this->resolveDiscount($promo);
        $days = collect(self::DAY_FIELDS)
            ->filter(fn (int $iso, string $field) => ($promo[$field] ?? 'false') === 'true')
            ->values()
            ->all();

        $title = $this->nullableField($promo, 'title')
            ?? trim(implode(' ', array_filter([$discountLabel, $wallet->name])));

        return new PromotionDTO(
            walletSlug: $wallet->slug,
            merchantName: $this->merchantName(),
            title: $title,
            category: null,
            description: $this->nullableField($promo, 'sub_title'),
            discountPercentage: $discountPercentage,
            cashbackPercentage: $cashbackPercentage,
            installments: $installments,
            validDays: $this->resolveValidDaysFromIso($days !== [] ? $days : null),
            startDate: $this->toDate($this->nullableField($promo, 'active_from')),
            endDate: $this->toDate($this->nullableField($promo, 'active_to')),
            terms: $this->nullableField($promo, 'legal'),
            url: self::SOURCE_URL,
            externalId: sha1('changomas|'.($promo['id'] ?? $title)),
            rawPayload: $promo,
        );
    }

    /**
     * The feed carries the percentage and the installment count in two
     * separate fields (never both at once) rather than one ambiguous
     * number like Cencosud's own feed — no "cuotas" text-sniffing needed
     * here. `discount_text_info` sometimes says "de reintegro" instead of
     * "de descuento" — the same reimbursement-vs-immediate-discount
     * distinction `MercadoPagoScraper::parseBadges()` already makes, so a
     * "reintegro" percentage is stored as `cashbackPercentage` instead of
     * `discountPercentage` here too.
     *
     * @param  array<string, string>  $promo
     * @return array{0: ?float, 1: ?float, 2: ?int, 3: ?string}
     */
    private function resolveDiscount(array $promo): array
    {
        $percentage = $this->nullableField($promo, 'discount_percentage');

        if ($percentage !== null && is_numeric($percentage)) {
            $info = $this->nullableField($promo, 'discount_text_info');
            $label = $info !== null ? "{$percentage}% {$info}" : "{$percentage}%";
            $isCashback = $info !== null && str_contains(mb_strtolower($info), 'reintegro');

            return $isCashback
                ? [null, (float) $percentage, null, $label]
                : [(float) $percentage, null, null, $label];
        }

        $installments = $this->nullableField($promo, 'discounts_amount_installments');

        if ($installments !== null && is_numeric($installments)) {
            $text = $this->nullableField($promo, 'discounts_text_installments') ?? 'cuotas';

            return [null, null, (int) $installments, "{$installments} {$text}"];
        }

        return [null, null, null, null];
    }

    private function nullableField(array $promo, string $key): ?string
    {
        $value = $promo[$key] ?? null;

        return ($value === null || $value === 'null' || trim((string) $value) === '') ? null : trim((string) $value);
    }

    private function toDate(?string $value): ?Carbon
    {
        return $value !== null ? Carbon::parse($value) : null;
    }
}
