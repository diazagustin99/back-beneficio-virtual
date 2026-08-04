<?php

namespace App\Scrapers\Icbc;

use App\Contracts\Scrapers\WalletScraperInterface;
use App\DTOs\PromotionDTO;
use App\DTOs\PromotionLocationDTO;
use App\Scrapers\Concerns\MakesHttpRequests;
use App\Scrapers\Concerns\ParsesForeignDates;
use Throwable;

/**
 * beneficios.icbc.com.ar/promo is a Vue SPA whose data comes from a shared
 * white-label benefits backend, `prod-utilidades-icbc.pisol.net` — the same
 * "Pisol" platform pattern already seen with BNA/Brubank's `digiventures.la`
 * backend in this project. Its `apikey`/`accesstoken` are hardcoded plaintext
 * in the site's own public JS bundle (`assets/index-*.js`, class `Pn`) and
 * sent by every anonymous visitor — same threat model as every other public
 * endpoint already scraped here.
 *
 * Unlike every other wallet, the whole catalog (279 promotions, confirmed
 * live) comes back from a *single* call to `beneficios/get` — no pagination
 * needed — and that one call is already maximally rich: full legal terms,
 * exact validity dates, day-of-week codes, accepted cards, category
 * (`rubro`) and per-segment discount/cashback/cap figures are all inline.
 * The only thing it doesn't have is physical store addresses, which live
 * behind a second call, `beneficios/detail?id={id}` (confirmed live —
 * discovered via the same JS bundle, alongside `beneficios/get`). That
 * second call is enrichment only, one promo at a time, after the whole
 * listing has already been fetched: a failure just leaves that promo
 * without `locations`, never aborts the scrape.
 */
class IcbcScraper implements WalletScraperInterface
{
    use MakesHttpRequests;
    use ParsesForeignDates;

    private const string API_BASE = 'https://prod-utilidades-icbc.pisol.net/api/web/v1/';

    private const string SITE_BASE = 'https://www.beneficios.icbc.com.ar/';

    private const string API_KEY = 'KlmhP3K1T5zowSqRIMMao6BSHrU48mCX';

    private const string ACCESS_TOKEN = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJhdXRoS2V5IjoiNTdiN2M0MmFiOGFlMmQ1MzA2ODk5NmQwODkwYWI5MGIiLCJleHAiOjI0MDkzOTc3NjN9.FGjs7O-bjiFPUwdiE-GQGDBXkVq0nXhAtR35CwbyaxY';

    /**
     * @var array<string, string>
     */
    private const array DAY_CODES = [
        'LU' => 'Lunes', 'MA' => 'Martes', 'MI' => 'Miércoles', 'JU' => 'Jueves',
        'VI' => 'Viernes', 'SA' => 'Sábado', 'DO' => 'Domingo',
    ];

    public function walletSlug(): string
    {
        return 'icbc';
    }

    public function scrape(): iterable
    {
        foreach ($this->fetchListing() as $item) {
            $merchant = $this->stringOrNull($item['store'] ?? null) ?? $this->stringOrNull($item['title'] ?? null);

            if ($merchant === null) {
                continue;
            }

            $dto = $this->buildDto($item, $merchant, $this->fetchLocations($item['id'] ?? null, $merchant));

            if ($dto !== null) {
                yield $dto;
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchListing(): array
    {
        $response = $this->http()
            ->withHeaders($this->headers())
            ->get(self::API_BASE.'beneficios/get', ['page' => 1])
            ->throw()
            ->json();

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];

        return array_values(array_filter($data, 'is_array'));
    }

    /**
     * Stage 2 enrichment for a single promo. Never throws — any failure
     * (network error, unexpected shape) yields an empty list, and the
     * caller simply leaves that promo without locations.
     *
     * @return PromotionLocationDTO[]
     */
    private function fetchLocations(mixed $id, string $merchant): array
    {
        if (! is_string($id) && ! is_int($id)) {
            return [];
        }

        try {
            $response = $this->http()
                ->withHeaders($this->headers())
                ->get(self::API_BASE.'beneficios/detail', ['id' => $id])
                ->throw()
                ->json();
        } catch (Throwable) {
            return [];
        }

        $locationsByProvince = $response['data']['locations'] ?? null;

        if (! is_array($locationsByProvince)) {
            return [];
        }

        $dtos = [];

        // `locations` is grouped by province name (e.g.
        // `{"Gran Buenos Aires": [{"street": ..., "city": ..., "state": ...,
        // "shopping": ...}]}`), not a flat list — confirmed live, unlike
        // every field on the listing entry itself. Each location has no
        // merchant/store name of its own, so the promotion's own is reused.
        foreach ($locationsByProvince as $entries) {
            if (! is_array($entries)) {
                continue;
            }

            foreach ($entries as $location) {
                if (! is_array($location)) {
                    continue;
                }

                $street = $this->stringOrNull($location['street'] ?? null);
                $city = $this->stringOrNull($location['city'] ?? null);

                if ($street === null && $city === null) {
                    continue;
                }

                $dtos[] = new PromotionLocationDTO(
                    scope: 'store',
                    province: $this->stringOrNull($location['state'] ?? null),
                    city: $city,
                    address: $street,
                    storeName: $merchant,
                );
            }
        }

        return $dtos;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  PromotionLocationDTO[]  $locations
     */
    private function buildDto(array $item, string $merchant, array $locations): ?PromotionDTO
    {
        $discount = is_numeric($item['ahorro_maximo'] ?? null) ? (float) $item['ahorro_maximo'] : null;
        $installments = is_numeric($item['cuotas_maximo'] ?? null) ? (int) $item['cuotas_maximo'] : null;
        $legal = $this->stringOrNull($item['legal'] ?? null);
        // No explicit discount-vs-cashback flag in this feed (unlike MODO's
        // `discount_mode`) — the legal text itself says "DE AHORRO ... SE
        // REINTEGRARÁN" for cashback-style promos vs "DE DESCUENTO" for a
        // straight discount, same heuristic already used for Prex/Mercado
        // Pago/Uala when a source doesn't expose this structurally.
        $isCashback = $legal !== null && preg_match('/reintegro|cashback/iu', $legal) === 1;

        return new PromotionDTO(
            walletSlug: $this->walletSlug(),
            merchantName: $merchant,
            title: $this->composeTitle($discount, $installments, $merchant),
            merchantIconUrl: $this->stringOrNull($item['url_logo'] ?? null),
            category: $this->stringOrNull($item['rubro'] ?? null),
            description: $this->stringOrNull($item['descripcion'] ?? null)
                ?? $this->stringOrNull($item['clarification'] ?? null)
                ?? $this->stringOrNull($item['observation'] ?? null),
            discountPercentage: $isCashback ? null : $discount,
            cashbackPercentage: $isCashback ? $discount : null,
            installments: $installments,
            reimbursementCap: $this->resolveReimbursementCap($item),
            validDays: $this->resolveValidDays($item),
            startDate: $this->parseIsoDate($this->stringOrNull($item['date_start'] ?? null)),
            endDate: $this->parseIsoDate($this->stringOrNull($item['date_end'] ?? null)),
            terms: $legal,
            url: $this->resolveUrl($item),
            externalId: isset($item['id']) ? (string) $item['id'] : null,
            paymentMethods: $this->resolvePaymentMethods($item),
            locations: $locations,
            rawPayload: $item,
        );
    }

    /**
     * No top-level cap field exists — each `segments[]` entry carries its
     * own `saving` (a currency cap, e.g. "POR TRANSACCIÓN"/"POR CUENTA POR
     * MES"), so the highest one across segments is used, same "max of
     * several capped candidates" approach as MODO's own reimbursement cap.
     *
     * @param  array<string, mixed>  $item
     */
    private function resolveReimbursementCap(array $item): ?float
    {
        $segments = is_array($item['segments'] ?? null) ? $item['segments'] : [];

        $candidates = array_filter(
            array_map(fn ($segment) => is_array($segment) ? ($segment['saving'] ?? null) : null, $segments),
            fn ($value) => is_numeric($value) && (float) $value > 0,
        );

        return $candidates === [] ? null : (float) max(array_map('floatval', $candidates));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return string[]
     */
    private function resolveValidDays(array $item): array
    {
        $codes = is_array($item['days'] ?? null) ? $item['days'] : [];
        $days = [];

        foreach (self::DAY_CODES as $code => $label) {
            if (in_array($code, $codes, true)) {
                $days[] = $label;
            }
        }

        return count($days) === 7 ? ['Todos los días'] : $days;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return string[]
     */
    private function resolvePaymentMethods(array $item): array
    {
        $cards = is_array($item['cards'] ?? null) ? $item['cards'] : [];
        $system = is_array($item['system'] ?? null) ? $item['system'] : [];

        $labels = array_map(
            fn ($method) => is_string($method) ? ucwords(mb_strtolower($method)) : null,
            array_merge($cards, $system),
        );

        return array_values(array_unique(array_filter($labels, fn ($label) => is_string($label) && $label !== '')));
    }

    /**
     * `web` is the merchant's own site when the source has one; `url_front`
     * (a relative slug) always exists as a fallback, pointing back at this
     * promo's own page on beneficios.icbc.com.ar.
     *
     * @param  array<string, mixed>  $item
     */
    private function resolveUrl(array $item): ?string
    {
        $web = $this->stringOrNull($item['web'] ?? null);

        if ($web !== null) {
            return $web;
        }

        $urlFront = $this->stringOrNull($item['url_front'] ?? null);

        return $urlFront !== null ? self::SITE_BASE.$urlFront : null;
    }

    /**
     * Neither `title` nor `store` is a promotional headline — both are just
     * the merchant's name — so this mirrors the site's own card copy
     * instead of leaving `title` as a bare merchant name whenever a
     * discount/installments figure is available.
     */
    private function composeTitle(?float $discount, ?int $installments, string $merchant): string
    {
        $parts = [];

        if ($discount !== null && $discount > 0) {
            $parts[] = ((int) $discount).'% de ahorro';
        }

        if ($installments !== null && $installments > 1) {
            $parts[] = "hasta {$installments} cuotas sin interés";
        }

        return $parts !== [] ? implode(' y ', $parts) : $merchant;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'apikey' => self::API_KEY,
            'accesstoken' => self::ACCESS_TOKEN,
            'Accept' => 'application/json',
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
