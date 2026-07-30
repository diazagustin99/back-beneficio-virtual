<?php

namespace App\DTOs;

readonly class PromotionLocationDTO
{
    public function __construct(
        public string $scope = 'nationwide',
        public ?string $province = null,
        public ?string $city = null,
        public ?string $address = null,
        public ?string $storeName = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'scope' => $this->scope,
            'province' => $this->province,
            'city' => $this->city,
            'address' => $this->address,
            'store_name' => $this->storeName,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];
    }
}
