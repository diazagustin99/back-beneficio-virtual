<?php

namespace Tests\Unit\Actions;

use App\Actions\Merchants\MergeDuplicateMerchantsAction;
use App\Models\Merchant;
use App\Models\Promotion;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class MergeDuplicateMerchantsActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_merges_into_the_variant_with_the_most_promotions_and_deletes_the_rest(): void
    {
        $winner = Merchant::factory()->create(['name' => 'Mimo&co']);
        $loser = Merchant::factory()->create(['name' => 'Mimo & Co.']);
        Promotion::factory()->count(3)->create(['merchant_id' => $winner->id]);
        Promotion::factory()->create(['merchant_id' => $loser->id]);

        $merges = app(MergeDuplicateMerchantsAction::class)->handle();

        $this->assertCount(1, $merges);
        $this->assertSame(1, $merges[0]['promotions_moved']);
        $this->assertModelMissing($loser);
        $this->assertSame(4, Promotion::where('merchant_id', $winner->id)->count());
    }

    public function test_copies_the_logo_from_a_variant_when_the_canonical_has_none(): void
    {
        $winner = Merchant::factory()->create(['name' => 'Carrefour', 'logo_url' => null]);
        Promotion::factory()->count(2)->create(['merchant_id' => $winner->id]);
        $loser = Merchant::factory()->create(['name' => 'CARREFOUR', 'logo_url' => 'https://example.com/logo.png']);
        Promotion::factory()->create(['merchant_id' => $loser->id]);

        app(MergeDuplicateMerchantsAction::class)->handle();

        $this->assertSame('https://example.com/logo.png', $winner->fresh()->logo_url);
    }

    public function test_ignores_merchants_with_no_normalized_duplicate(): void
    {
        Merchant::factory()->create(['name' => 'Carrefour']);
        Merchant::factory()->create(['name' => 'Coto']);

        $merges = app(MergeDuplicateMerchantsAction::class)->handle();

        $this->assertSame([], $merges);
        $this->assertSame(2, Merchant::count());
    }

    public function test_running_it_twice_is_a_no_op_the_second_time(): void
    {
        Merchant::factory()->create(['name' => 'Mimo&co']);
        Merchant::factory()->create(['name' => 'Mimo & Co.']);
        $action = app(MergeDuplicateMerchantsAction::class);

        $first = $action->handle();
        $second = $action->handle();

        $this->assertCount(1, $first);
        $this->assertSame([], $second);
        $this->assertSame(1, Merchant::count());
    }

    public function test_merges_a_promotional_sentence_into_the_existing_merchant_named_inside_it(): void
    {
        $carrefour = Merchant::factory()->create(['name' => 'Carrefour']);
        Promotion::factory()->count(2)->create(['merchant_id' => $carrefour->id]);
        $sentence = Merchant::factory()->create(['name' => 'Ahorrá en Carrefour']);
        Promotion::factory()->create(['merchant_id' => $sentence->id]);

        $merges = app(MergeDuplicateMerchantsAction::class)->handle();

        $this->assertCount(1, $merges);
        $this->assertModelMissing($sentence);
        $this->assertSame(3, Promotion::where('merchant_id', $carrefour->id)->count());
    }

    public function test_does_not_merge_a_sentence_that_matches_more_than_one_known_chain(): void
    {
        Merchant::factory()->create(['name' => 'Carrefour']);
        Merchant::factory()->create(['name' => 'Coto']);
        Merchant::factory()->create(['name' => 'Comparamos precios: Carrefour vs Coto']);

        $merges = app(MergeDuplicateMerchantsAction::class)->handle();

        $this->assertSame([], $merges);
        $this->assertSame(3, Merchant::count());
    }

    public function test_leaves_single_word_merchants_alone(): void
    {
        Merchant::factory()->create(['name' => 'Carrefour']);
        Merchant::factory()->create(['name' => 'Coto']);

        $merges = app(MergeDuplicateMerchantsAction::class)->handle();

        $this->assertSame([], $merges);
        $this->assertSame(2, Merchant::count());
    }

    /**
     * Regression test for a real incident — see `MerchantWordMatcher`'s own
     * docblock for the full story. The fix must only ever match a short,
     * curated list of known chains, never an arbitrary existing merchant
     * name, however exactly a word matches it.
     */
    public function test_never_merges_into_a_generic_word_that_happens_to_already_be_a_merchant(): void
    {
        $generic = Merchant::factory()->create(['name' => 'Repuestos']);
        Merchant::factory()->create(['name' => 'Repuestos Ford']);
        Merchant::factory()->create(['name' => 'Repuestos Nea']);

        $merges = app(MergeDuplicateMerchantsAction::class)->handle();

        $this->assertSame([], $merges);
        $this->assertSame(3, Merchant::count());
        $this->assertSame(0, $generic->promotions()->count());
    }

    /**
     * Regression test for a real incident: "Riadigos" is a pharmacy chain
     * with a stand inside a Carrefour hypermarket (Naranja X lists it as
     * "Riadigos Carrefour") — a genuinely different business that used to
     * silently merge into the "Carrefour" supermarket merchant.
     */
    public function test_never_merges_a_different_business_that_merely_mentions_a_known_chain(): void
    {
        $carrefour = Merchant::factory()->create(['name' => 'Carrefour']);
        Merchant::factory()->create(['name' => 'Riadigos Carrefour']);

        $merges = app(MergeDuplicateMerchantsAction::class)->handle();

        $this->assertSame([], $merges);
        $this->assertSame(2, Merchant::count());
        $this->assertSame(0, $carrefour->promotions()->count());
    }
}
