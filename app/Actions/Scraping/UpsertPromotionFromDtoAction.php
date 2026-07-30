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
            $merchant = $this->resolveMerchant->handle($dto->merchantName, $dto->merchantIconUrl);
            $category = $this->resolveCategory->handle($dto->category);

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
                'discount_percentage' => $dto->discountPercentage,
                'fixed_amount' => $dto->fixedAmount,
                'cashback_percentage' => $dto->cashbackPercentage,
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
}
