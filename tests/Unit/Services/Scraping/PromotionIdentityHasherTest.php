<?php

namespace Tests\Unit\Services\Scraping;

use App\Services\Scraping\PromotionIdentityHasher;
use PHPUnit\Framework\TestCase;

class PromotionIdentityHasherTest extends TestCase
{
    private PromotionIdentityHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hasher = new PromotionIdentityHasher;
    }

    public function test_same_external_id_produces_same_hash(): void
    {
        $first = $this->hasher->hash('mercado_pago', 'abc123', 'Carrefour', 'Title A', 'https://mp.com/a');
        $second = $this->hasher->hash('mercado_pago', 'abc123', 'Carrefour', 'Title B changed', 'https://mp.com/b');

        $this->assertSame($first, $second);
    }

    public function test_different_external_id_produces_different_hash(): void
    {
        $first = $this->hasher->hash('mercado_pago', 'abc123', 'Carrefour', 'Title', 'https://mp.com/a');
        $second = $this->hasher->hash('mercado_pago', 'xyz789', 'Carrefour', 'Title', 'https://mp.com/a');

        $this->assertNotSame($first, $second);
    }

    public function test_null_external_id_falls_back_to_merchant_title_and_url(): void
    {
        $first = $this->hasher->hash('mercado_pago', null, 'Carrefour', 'Title', 'https://mp.com/a');
        $second = $this->hasher->hash('mercado_pago', null, 'Carrefour', 'Different title', 'https://mp.com/a');

        $this->assertNotSame($first, $second, 'A different title without an external_id should change the fallback hash.');
    }

    public function test_fallback_hash_is_stable_across_runs_with_identical_input(): void
    {
        $first = $this->hasher->hash('mercado_pago', null, 'Carrefour', 'Title', 'https://mp.com/a');
        $second = $this->hasher->hash('mercado_pago', null, 'Carrefour', 'Title', 'https://mp.com/a');

        $this->assertSame($first, $second);
    }

    public function test_fallback_hash_ignores_url_query_string(): void
    {
        $first = $this->hasher->hash('mercado_pago', null, 'Carrefour', 'Title', 'https://mp.com/a?utm=x');
        $second = $this->hasher->hash('mercado_pago', null, 'Carrefour', 'Title', 'https://mp.com/a?utm=y');

        $this->assertSame($first, $second);
    }

    public function test_same_fallback_fields_under_different_wallet_produce_different_hash(): void
    {
        $first = $this->hasher->hash('mercado_pago', null, 'Carrefour', 'Title', 'https://mp.com/a');
        $second = $this->hasher->hash('uala', null, 'Carrefour', 'Title', 'https://mp.com/a');

        $this->assertNotSame($first, $second);
    }

    public function test_normalizes_case_and_surrounding_whitespace(): void
    {
        $first = $this->hasher->hash('mercado_pago', ' ABC123 ', 'Carrefour', 'Title', 'https://mp.com/a');
        $second = $this->hasher->hash('MERCADO_PAGO', 'abc123', 'carrefour', 'title', 'https://mp.com/a');

        $this->assertSame($first, $second);
    }
}
