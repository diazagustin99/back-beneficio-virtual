<?php

namespace App\DTOs;

use DateTimeInterface;

/**
 * Common shape every wallet scraper must produce, regardless of source format.
 */
readonly class PromotionDTO
{
    /**
     * @param  string[]  $validDays
     * @param  string[]  $paymentMethods
     * @param  PromotionLocationDTO[]  $locations
     * @param  array<string, mixed>  $rawPayload  Original scraped payload, kept for debugging.
     */
    public function __construct(
        public string $walletSlug,
        public string $merchantName,
        public string $title,
        public ?string $merchantIconUrl = null,
        public ?string $category = null,
        public ?string $description = null,
        public ?float $discountPercentage = null,
        public ?float $fixedAmount = null,
        public ?float $cashbackPercentage = null,
        public ?int $installments = null,
        public ?float $reimbursementCap = null,
        public ?float $minimumPurchase = null,
        public array $validDays = [],
        public ?DateTimeInterface $startDate = null,
        public ?DateTimeInterface $endDate = null,
        public ?string $terms = null,
        public ?string $url = null,
        public ?string $externalId = null,
        public array $paymentMethods = [],
        public array $locations = [],
        public array $rawPayload = [],
    ) {}
}
