<?php

namespace App\Actions\Scraping;

use App\DTOs\PromotionDTO;
use App\Models\Promotion;
use App\Models\PromotionSnapshot;
use App\Models\PromotionSource;
use App\Models\ScrapeRun;
use App\Models\Wallet;
use App\Services\Scraping\PromotionIdentityHasher;
use Illuminate\Support\Facades\DB;

class UpsertPromotionFromDtoAction
{
    public function __construct(
        private readonly ResolveMerchantAction $resolveMerchant,
        private readonly ResolvePromotionCategoryAction $resolveCategory,
        private readonly PromotionIdentityHasher $hasher,
    ) {}

    /**
     * Create or update a single promotion from a scraped DTO. Never depends on
     * which wallet it came from beyond the `$wallet` argument itself.
     *
     * @return array{promotion: Promotion, status: 'created'|'updated'|'unchanged'}
     */
    public function handle(Wallet $wallet, PromotionDTO $dto, ScrapeRun $scrapeRun): array
    {
        return DB::transaction(function () use ($wallet, $dto, $scrapeRun) {
            $category = $this->resolveCategory->handle($dto->category);
            $isGenericAdhered = $this->isGenericAdheredMerchantsText($dto->merchantName);

            $merchantName = match (true) {
                $this->isBannerText($dto->merchantName) => $wallet->name,
                $isGenericAdhered => $category?->name ?? $wallet->name,
                default => $dto->merchantName,
            };

            // A "Comercios de gastronomía adheridos"-style DTO's own icon
            // (if any) belongs to whichever specific business the feed
            // happened to attach it to that day — passing it through would
            // make this category-wide merchant's logo flip-flop between
            // unrelated photos as different generic promos get processed.
            $merchantIconUrl = $isGenericAdhered ? null : $dto->merchantIconUrl;
            $merchant = $this->resolveMerchant->handle($merchantName, $merchantIconUrl);

            $identityHash = $this->hasher->hash(
                $wallet->slug,
                $dto->externalId,
                $dto->merchantName,
                $dto->title,
                $dto->url,
            );

            $attributes = [
                'merchant_id' => $merchant->id,
                'promotion_category_id' => $category?->id,
                'title' => $dto->title,
                'description' => $dto->description,
                'discount_percentage' => $this->nullIfZero($dto->discountPercentage),
                'fixed_amount' => $this->nullIfZero($dto->fixedAmount),
                'cashback_percentage' => $this->nullIfZero($dto->cashbackPercentage),
                'installments' => $dto->installments,
                'reimbursement_cap' => $dto->reimbursementCap,
                'minimum_purchase' => $dto->minimumPurchase,
                'valid_days' => $dto->validDays,
                'starts_at' => $dto->startDate,
                'ends_at' => $dto->endDate,
                'terms' => $dto->terms,
                'url' => $dto->url,
            ];

            $promotion = Promotion::query()
                ->where('wallet_id', $wallet->id)
                ->where('identity_hash', $identityHash)
                ->first();

            $status = 'unchanged';

            if ($promotion === null) {
                $promotion = new Promotion([
                    ...$attributes,
                    'wallet_id' => $wallet->id,
                    'identity_hash' => $identityHash,
                    'version' => 1,
                    'first_seen_at' => now(),
                ]);
                $status = 'created';
            } else {
                $original = $promotion->only(Promotion::TRACKED_FIELDS);
                $promotion->fill($attributes);

                if ($promotion->isDirty(Promotion::TRACKED_FIELDS)) {
                    PromotionSnapshot::create([
                        'promotion_id' => $promotion->id,
                        'scrape_run_id' => $scrapeRun->id,
                        'version' => $promotion->version,
                        'data' => $original,
                    ]);

                    $promotion->version++;
                    $status = 'updated';
                }
            }

            $promotion->external_id = $dto->externalId;
            $promotion->is_active = true;
            $promotion->deactivated_at = null;
            $promotion->last_seen_at = now();
            $promotion->last_scrape_run_id = $scrapeRun->id;
            $promotion->save();

            $promotion->locations()->delete();
            $promotion->locations()->createMany(
                array_map(fn ($location) => $location->toArray(), $dto->locations),
            );

            $promotion->paymentMethods()->delete();
            $promotion->paymentMethods()->createMany(
                array_map(fn (string $name) => ['name' => $name], array_values(array_unique($dto->paymentMethods))),
            );

            PromotionSource::updateOrCreate(
                ['promotion_id' => $promotion->id, 'scrape_run_id' => $scrapeRun->id],
                [
                    'wallet_id' => $wallet->id,
                    'external_id' => $dto->externalId,
                    'raw_payload' => $dto->rawPayload,
                ],
            );

            return ['promotion' => $promotion, 'status' => $status];
        });
    }

    /**
     * A source reporting exactly `0` for a discount/cashback/fixed-amount
     * figure isn't "a discount of 0%" — it means the field doesn't apply to
     * this promotion (e.g. a MODO "cuotas sin interés" promo with no
     * discount at all). Treated the same as the field being absent, so the
     * frontend never renders a misleading "0% OFF" badge.
     */
    private function nullIfZero(?float $value): ?float
    {
        return $value === 0.0 ? null : $value;
    }

    /**
     * Some sources (Cuenta DNI, MODO) occasionally have no real merchant for
     * a promotion — a wallet-wide campaign — and send a marketing sentence
     * ("¡Al super con Cuenta DNI!") in the field that's supposed to be the
     * merchant name. Detected by the same shape a human would recognize it
     * by: a full exclamation or question, not a name. Redirected to the
     * wallet's own name instead of creating a one-off fake merchant for
     * every distinct banner sentence.
     */
    private function isBannerText(string $name): bool
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            return false;
        }

        return (str_starts_with($trimmed, '¡') && str_ends_with($trimmed, '!'))
            || (str_starts_with($trimmed, '¿') && str_ends_with($trimmed, '?'));
    }

    /**
     * Both Macro's and MODO's own feeds occasionally name a promotion "any
     * adhered business in this category" instead of one specific merchant
     * — "Comercios de gastronomía adheridos", "Consultá los locales
     * adheridos", "Comercios que acepten MODO" — confirmed live on both,
     * same wording. Verified against every real merchant name either
     * wallet has actually produced: no real business name starts with
     * "Comercios" or contains "adherid[oa]" — see
     * plans/0023-macro-comercios-genericos.md for the full evidence (e.g.
     * "Tienda Newsan"/"Tienda Galicia" are real merchants and neither
     * matches).
     */
    private function isGenericAdheredMerchantsText(string $name): bool
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            return false;
        }

        return preg_match('/^comercios\b/iu', $trimmed) === 1
            || preg_match('/adherid/iu', $trimmed) === 1;
    }
}
