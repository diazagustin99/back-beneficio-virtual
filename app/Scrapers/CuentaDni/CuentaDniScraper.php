<?php

namespace App\Scrapers\CuentaDni;

use App\Contracts\Scrapers\WalletScraperInterface;
use App\DTOs\PromotionDTO;
use App\Scrapers\Concerns\MakesHttpRequests;
use App\Scrapers\Concerns\ParsesForeignDates;

/**
 * Cuenta DNI (Banco Provincia) exposes a plain ASP.NET MVC JSON endpoint per
 * rubro (category) — there's no single "all" id, so this loops the real
 * category ids taken from the live `<select id="select-rubros">` on
 * https://www.bancoprovincia.com.ar/cuentadni/contenidos/cdnibeneficios/.
 */
class CuentaDniScraper implements WalletScraperInterface
{
    use MakesHttpRequests;
    use ParsesForeignDates;

    private const string ENDPOINT = 'https://www.bancoprovincia.com.ar/cuentadni/Home/GetBeneficioByRubro';

    private const string REFERER = 'https://www.bancoprovincia.com.ar/cuentadni/contenidos/cdnibeneficios/';

    /**
     * The `logo` field is only an icon name (e.g. "icono_garrafas"), resolved
     * through this CDN path — the same pattern used by the rubro `<select>`
     * on the live page (`style="background-image:url(/CDN/Get/{name})"`).
     */
    private const string ICON_BASE_URL = 'https://www.bancoprovincia.com.ar/CDN/Get/';

    /**
     * @var array<int, string>
     */
    private const array CATEGORIES = [
        1 => 'Varios',
        2 => 'Garrafas',
        27 => 'Supermercados',
        32 => 'Alimentos',
        34 => 'Promo Acumulable',
    ];

    public function walletSlug(): string
    {
        return 'cuenta_dni';
    }

    public function scrape(): iterable
    {
        foreach (self::CATEGORIES as $rubroId => $categoryName) {
            $items = $this->http()
                ->withHeaders([
                    'Referer' => self::REFERER,
                    'X-Requested-With' => 'XMLHttpRequest',
                ])
                ->get(self::ENDPOINT, ['idRubro' => $rubroId])
                ->throw()
                ->json();

            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $dto = $this->itemToDto($item, $categoryName);

                if ($dto !== null) {
                    yield $dto;
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function itemToDto(array $item, string $category): ?PromotionDTO
    {
        if ((int) ($item['oculto'] ?? 0) === 1) {
            return null;
        }

        $merchant = $item['titulo'] ?? null;

        if (! is_string($merchant) || $merchant === '') {
            return null;
        }

        $title = $item['titulo_fecha'] ?? null;
        $title = is_string($title) && $title !== '' ? $title : $merchant;

        $endDate = $this->parseDotNetDate(is_string($item['fecha_hasta'] ?? null) ? $item['fecha_hasta'] : null);

        // The endpoint returns its entire historical catalog with no date
        // filtering at all — live-checked: 177 of 179 items in "Supermercados"
        // already had a `fecha_hasta` in the past (some from 2020). Anything
        // whose expiry is before today is discarded here rather than
        // persisted as an "active" promotion.
        if ($endDate !== null && $endDate->startOfDay()->isBefore(now()->startOfDay())) {
            return null;
        }

        $description = $item['subtitulo'] ?? $item['bajada'] ?? null;
        $legal = $item['legal'] ?? null;
        $url = $item['url'] ?? null;
        $percentage = $item['porcentaje'] ?? null;
        $logo = $item['logo'] ?? null;

        return new PromotionDTO(
            walletSlug: $this->walletSlug(),
            merchantName: $merchant,
            title: $title,
            merchantIconUrl: is_string($logo) && $logo !== '' ? self::ICON_BASE_URL.$logo : null,
            category: $category,
            description: is_string($description) && $description !== '' ? $description : null,
            discountPercentage: is_numeric($percentage) ? (float) $percentage : null,
            startDate: $this->parseDotNetDate(is_string($item['fecha_desde'] ?? null) ? $item['fecha_desde'] : null),
            endDate: $endDate,
            terms: is_string($legal) && $legal !== '' ? $legal : null,
            url: is_string($url) && $url !== '' ? $url : null,
            externalId: isset($item['id']) ? (string) $item['id'] : null,
            rawPayload: $item,
        );
    }
}
