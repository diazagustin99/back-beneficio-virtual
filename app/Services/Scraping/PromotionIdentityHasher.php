<?php

namespace App\Services\Scraping;

/**
 * Computes the deterministic key used to match a promotion across scrape
 * runs, regardless of whether the source wallet exposes a stable ID.
 */
class PromotionIdentityHasher
{
    public function hash(
        string $walletSlug,
        ?string $externalId,
        string $merchantName,
        string $title,
        ?string $url,
    ): string {
        $subject = $externalId !== null && $externalId !== ''
            ? 'external:'.$this->normalize($externalId)
            : 'fallback:'.$this->normalize($merchantName).'|'.$this->normalize($title).'|'.$this->normalizeUrl($url);

        return hash('sha256', $this->normalize($walletSlug).'|'.$subject);
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private function normalizeUrl(?string $url): string
    {
        if ($url === null || $url === '') {
            return '';
        }

        $path = parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST).parse_url($url, PHP_URL_PATH);

        return $this->normalize(rtrim($path, '/'));
    }
}
