<?php

namespace Tests\Unit\Actions;

use App\Actions\Merchants\MergeDuplicateMerchantsAction;
use App\Models\Merchant;
use App\Models\Promotion;
use App\Models\PromotionCategory;
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

    public function test_merges_a_known_alias_into_the_existing_canonical_merchant(): void
    {
        config(['merchant_aliases.aerolineasarg' => 'Aerolíneas Argentinas']);
        $canonical = Merchant::factory()->create(['name' => 'Aerolíneas Argentinas']);
        Promotion::factory()->count(2)->create(['merchant_id' => $canonical->id]);
        $variant = Merchant::factory()->create(['name' => 'Aerolineas Arg']);
        Promotion::factory()->create(['merchant_id' => $variant->id]);

        $merges = app(MergeDuplicateMerchantsAction::class)->handle();

        $this->assertCount(1, $merges);
        $this->assertModelMissing($variant);
        $this->assertSame(3, Promotion::where('merchant_id', $canonical->id)->count());
    }

    public function test_renames_a_known_alias_when_the_canonical_merchant_does_not_exist_yet(): void
    {
        config(['merchant_aliases.aerolineasarg' => 'Aerolíneas Argentinas']);
        $variant = Merchant::factory()->create(['name' => 'Aerolineas Arg']);
        Promotion::factory()->create(['merchant_id' => $variant->id]);

        $merges = app(MergeDuplicateMerchantsAction::class)->handle();

        $this->assertSame([], $merges);
        $this->assertSame('Aerolíneas Argentinas', $variant->fresh()->name);
        $this->assertSame(1, Merchant::count());
    }

    /**
     * Regression test for a real incident: Aiello supermarkets got scraped
     * under 3 different name variants across 4 wallets (reversed word
     * order, all-caps, or just the surname) — all real config entries,
     * not faked, so this fails if `config/merchant_aliases.php` regresses.
     */
    public function test_unifies_every_known_aiello_variant_into_the_real_canonical_merchant(): void
    {
        $canonical = Merchant::factory()->create(['name' => 'Aiello Supermercados S. A']);
        Promotion::factory()->create(['merchant_id' => $canonical->id]);
        $reversed = Merchant::factory()->create(['name' => 'Supermercados Aiello']);
        Promotion::factory()->create(['merchant_id' => $reversed->id]);
        $bareName = Merchant::factory()->create(['name' => 'Aiello']);
        Promotion::factory()->count(2)->create(['merchant_id' => $bareName->id]);
        $shouting = Merchant::factory()->create(['name' => 'AIELLO SUPERMERCADOS']);
        Promotion::factory()->count(3)->create(['merchant_id' => $shouting->id]);
        // A genuinely different business that merely shares the surname —
        // must survive untouched, same spirit as the Riadigos/Carrefour case.
        $unrelated = Merchant::factory()->create(['name' => 'Aiello Morrone Decoraciones']);

        app(MergeDuplicateMerchantsAction::class)->handle();

        $this->assertModelMissing($reversed);
        $this->assertModelMissing($bareName);
        $this->assertModelMissing($shouting);
        $this->assertModelExists($unrelated);
        $this->assertSame('Aiello Morrone Decoraciones', $unrelated->fresh()->name);
        $this->assertSame(7, Promotion::where('merchant_id', $canonical->id)->count());
        $this->assertSame(2, Merchant::count());
    }

    public function test_merges_a_con_modo_suffixed_name_into_the_existing_clean_merchant(): void
    {
        $canonical = Merchant::factory()->create(['name' => 'AG Piazze']);
        Promotion::factory()->create(['merchant_id' => $canonical->id]);
        $variant = Merchant::factory()->create(['name' => 'AG Piazze con MODO']);
        Promotion::factory()->create(['merchant_id' => $variant->id]);

        $merges = app(MergeDuplicateMerchantsAction::class)->handle();

        $this->assertCount(1, $merges);
        $this->assertModelMissing($variant);
        $this->assertSame(2, Promotion::where('merchant_id', $canonical->id)->count());
    }

    /**
     * Regression case for the real Supervielle incident: the exact same
     * business ended up scraped as two different `marca` variants
     * ("AG Piazze con MODO" and "AG Piazze con MODO - Plan Sueldo"), neither
     * of which is the clean "AG Piazze" name. The first variant processed
     * gets renamed to "AG Piazze" (nothing to merge into yet), which then
     * becomes the canonical the second variant merges into within the same
     * pass — proving the merge doesn't rely on a stale, pre-fetched list.
     */
    public function test_unifies_two_con_modo_suffixed_variants_of_the_same_business_with_no_prior_clean_merchant(): void
    {
        $variantA = Merchant::factory()->create(['name' => 'AG Piazze con MODO']);
        Promotion::factory()->create(['merchant_id' => $variantA->id]);
        $variantB = Merchant::factory()->create(['name' => 'AG Piazze con MODO - Plan Sueldo']);
        Promotion::factory()->create(['merchant_id' => $variantB->id]);

        app(MergeDuplicateMerchantsAction::class)->handle();

        $this->assertSame(1, Merchant::count());
        $clean = Merchant::sole();
        $this->assertSame('AG Piazze', $clean->name);
        $this->assertSame(2, $clean->promotions()->count());
    }

    public function test_merges_a_con_visa_suffixed_name_into_the_existing_clean_merchant(): void
    {
        $canonical = Merchant::factory()->create(['name' => 'Coto']);
        Promotion::factory()->create(['merchant_id' => $canonical->id]);
        $variant = Merchant::factory()->create(['name' => 'COTO con Visa débito NFC']);
        Promotion::factory()->create(['merchant_id' => $variant->id]);

        $merges = app(MergeDuplicateMerchantsAction::class)->handle();

        $this->assertCount(1, $merges);
        $this->assertModelMissing($variant);
        $this->assertSame(2, Promotion::where('merchant_id', $canonical->id)->count());
    }

    public function test_renames_a_con_visa_suffixed_name_when_the_canonical_merchant_does_not_exist_yet(): void
    {
        $variant = Merchant::factory()->create(['name' => 'Pases con Visa Signature NFC']);
        Promotion::factory()->create(['merchant_id' => $variant->id]);

        $merges = app(MergeDuplicateMerchantsAction::class)->handle();

        $this->assertSame([], $merges);
        $this->assertSame('Pases', $variant->fresh()->name);
        $this->assertSame(1, Merchant::count());
    }

    public function test_leaves_a_merchant_name_alone_when_it_has_no_con_modo_suffix(): void
    {
        Merchant::factory()->create(['name' => 'Bridgestone']);

        $merges = app(MergeDuplicateMerchantsAction::class)->handle();

        $this->assertSame([], $merges);
        $this->assertSame('Bridgestone', Merchant::sole()->name);
    }

    public function test_merges_a_segment_qualifier_suffixed_name_into_the_existing_clean_merchant(): void
    {
        $canonical = Merchant::factory()->create(['name' => 'Disco']);
        Promotion::factory()->create(['merchant_id' => $canonical->id]);
        $variant = Merchant::factory()->create(['name' => 'Disco - Jubilados']);
        Promotion::factory()->create(['merchant_id' => $variant->id]);

        $merges = app(MergeDuplicateMerchantsAction::class)->handle();

        $this->assertCount(1, $merges);
        $this->assertModelMissing($variant);
        $this->assertSame(2, Promotion::where('merchant_id', $canonical->id)->count());
    }

    public function test_renames_a_segment_qualifier_suffixed_name_when_the_canonical_merchant_does_not_exist_yet(): void
    {
        $variant = Merchant::factory()->create(['name' => 'Almundo - Paquetes']);
        Promotion::factory()->create(['merchant_id' => $variant->id]);

        $merges = app(MergeDuplicateMerchantsAction::class)->handle();

        $this->assertSame([], $merges);
        $this->assertSame('Almundo', $variant->fresh()->name);
        $this->assertSame(1, Merchant::count());
    }

    /**
     * Regression test for a real incident: "Jubilados" genuinely appears
     * as an ordinary word in several *real* merchant names (a retirees'
     * community center, promotional badges like "FARMACIA JUBILADOS") —
     * none of these have the "- {qualifier}" shape, so none of them must
     * be altered.
     */
    public function test_never_touches_a_genuine_merchant_name_that_merely_contains_a_qualifier_word(): void
    {
        Merchant::factory()->create(['name' => 'Centro de Jubilados y Pens. de Ctes']);
        Merchant::factory()->create(['name' => 'FARMACIA JUBILADOS']);
        Merchant::factory()->create(['name' => 'ChangoMás Jubilados']);

        $merges = app(MergeDuplicateMerchantsAction::class)->handle();

        $this->assertSame([], $merges);
        $this->assertSame(3, Merchant::count());
        $this->assertSame(
            ['Centro de Jubilados y Pens. de Ctes', 'FARMACIA JUBILADOS', 'ChangoMás Jubilados'],
            Merchant::pluck('name')->all(),
        );
    }

    /**
     * Regression test for a real incident: the same "Alvear" supermarket
     * chain got scraped under 2 different name variants — all real config
     * entries, not faked. "Alvear" is also a very common street name, so
     * several genuinely unrelated businesses (a pharmacy, an optical store,
     * a mattress store, the Jockey Club) share the word in the real data —
     * none of those are touched by this alias, only the exact name matches.
     */
    public function test_unifies_every_known_alvear_variant_into_the_real_canonical_merchant(): void
    {
        $canonical = Merchant::factory()->create(['name' => 'Alvear Supermercados']);
        Promotion::factory()->create(['merchant_id' => $canonical->id]);
        $reversed = Merchant::factory()->create(['name' => 'Supermercados Alvear']);
        Promotion::factory()->create(['merchant_id' => $reversed->id]);
        $bareName = Merchant::factory()->create(['name' => 'Alvear']);
        Promotion::factory()->count(2)->create(['merchant_id' => $bareName->id]);
        // A genuinely different business that merely sits on the same street
        // — must survive untouched, same spirit as the Riadigos/Carrefour case.
        $unrelated = Merchant::factory()->create(['name' => 'Farmacia Alvear']);

        app(MergeDuplicateMerchantsAction::class)->handle();

        $this->assertModelMissing($reversed);
        $this->assertModelMissing($bareName);
        $this->assertModelExists($unrelated);
        $this->assertSame('Farmacia Alvear', $unrelated->fresh()->name);
        $this->assertSame(4, Promotion::where('merchant_id', $canonical->id)->count());
        $this->assertSame(2, Merchant::count());
    }

    /**
     * Regression test for a real incident: Naranja X scraped the exact same
     * "Hasta 10 cuotas cero interés" promotion under both "Angler Sa" and the
     * bare "Angler" — with the bare variant miscategorized as "Supermercados"
     * instead of "Construcción". "Wrangler" (an unrelated clothing brand)
     * merely shares the substring and must never be touched.
     */
    public function test_unifies_the_known_angler_variant_into_the_real_canonical_merchant(): void
    {
        $canonical = Merchant::factory()->create(['name' => 'Angler Sa']);
        Promotion::factory()->count(3)->create(['merchant_id' => $canonical->id]);
        $variant = Merchant::factory()->create(['name' => 'Angler']);
        Promotion::factory()->count(2)->create(['merchant_id' => $variant->id]);
        $unrelated = Merchant::factory()->create(['name' => 'Wrangler']);

        app(MergeDuplicateMerchantsAction::class)->handle();

        $this->assertModelMissing($variant);
        $this->assertModelExists($unrelated);
        $this->assertSame(5, Promotion::where('merchant_id', $canonical->id)->count());
        $this->assertSame(2, Merchant::count());
    }

    /**
     * Regression test for a real incident: the paint store "Andres Merino
     * Pinturerias" also got scraped as the bare, all-caps "ANDRES MERINO"
     * with no category (fell into the generic "Otros" bucket).
     */
    public function test_unifies_the_known_andres_merino_variant_into_the_real_canonical_merchant(): void
    {
        $canonical = Merchant::factory()->create(['name' => 'Andres Merino Pinturerias']);
        Promotion::factory()->count(3)->create(['merchant_id' => $canonical->id]);
        $variant = Merchant::factory()->create(['name' => 'ANDRES MERINO']);
        Promotion::factory()->create(['merchant_id' => $variant->id]);

        $merges = app(MergeDuplicateMerchantsAction::class)->handle();

        $this->assertCount(1, $merges);
        $this->assertModelMissing($variant);
        $this->assertSame(4, Promotion::where('merchant_id', $canonical->id)->count());
    }

    /**
     * Regression test for a real incident: the "Arco Iris" supermarket chain
     * got scraped under 6 different name variants — apparently once per
     * branch/legal-entity name, since Naranja X repeats the exact same
     * "Hasta 6 cuotas cero interés" promotion across several of them. "Arco
     * Iris" is also a common phrase used by several genuinely unrelated
     * businesses in the real data (a paint store, a toy store, a shopping
     * mall, a party-supply store) — none of those are touched, only the
     * exact name matches.
     */
    public function test_unifies_every_known_arco_iris_variant_into_the_real_canonical_merchant(): void
    {
        $canonical = Merchant::factory()->create(['name' => 'Arcoiris Supermercados']);
        Promotion::factory()->create(['merchant_id' => $canonical->id]);
        $spacedReversed = Merchant::factory()->create(['name' => 'Supermercados Arco Iris']);
        Promotion::factory()->count(2)->create(['merchant_id' => $spacedReversed->id]);
        $singularNoSpace = Merchant::factory()->create(['name' => 'Supermercado Arcoiris']);
        Promotion::factory()->create(['merchant_id' => $singularNoSpace->id]);
        $bareSpaced = Merchant::factory()->create(['name' => 'Arco Iris']);
        Promotion::factory()->count(2)->create(['merchant_id' => $bareSpaced->id]);
        $branch = Merchant::factory()->create(['name' => 'Arco Iris Super Suc Baigorria']);
        Promotion::factory()->create(['merchant_id' => $branch->id]);
        $branchElectro = Merchant::factory()->create(['name' => 'Arco Iris Super Suc Baigorria Electro']);
        Promotion::factory()->create(['merchant_id' => $branchElectro->id]);
        $group = Merchant::factory()->create(['name' => 'Arco Iris Group']);
        Promotion::factory()->create(['merchant_id' => $group->id]);
        // Genuinely different businesses that merely share the phrase — must
        // survive untouched, same spirit as the Riadigos/Carrefour case.
        $paintStore = Merchant::factory()->create(['name' => 'Arco Iris Pintureria']);
        $toyStore = Merchant::factory()->create(['name' => 'Jugueteria Arco Iris']);

        app(MergeDuplicateMerchantsAction::class)->handle();

        $this->assertModelMissing($spacedReversed);
        $this->assertModelMissing($singularNoSpace);
        $this->assertModelMissing($bareSpaced);
        $this->assertModelMissing($branch);
        $this->assertModelMissing($branchElectro);
        $this->assertModelMissing($group);
        $this->assertModelExists($paintStore);
        $this->assertModelExists($toyStore);
        $this->assertSame(9, Promotion::where('merchant_id', $canonical->id)->count());
        $this->assertSame(3, Merchant::count());
    }

    /**
     * Regression test for a real pattern found scanning the whole catalog:
     * the same small chain scraped under 3 differently-worded names, none
     * of which is an exact duplicate or a known alias — just a generic
     * business-type word ("Supermercado(s)") added/dropped/reordered.
     */
    public function test_merges_names_that_differ_only_by_generic_business_words(): void
    {
        $supermercados = PromotionCategory::factory()->create(['name' => 'Supermercados']);
        $canonical = Merchant::factory()->create(['name' => 'Toledo']);
        Promotion::factory()->count(3)->create(['merchant_id' => $canonical->id, 'promotion_category_id' => $supermercados->id]);
        $reversed = Merchant::factory()->create(['name' => 'Supermercados Toledo']);
        Promotion::factory()->create(['merchant_id' => $reversed->id, 'promotion_category_id' => $supermercados->id]);
        $singular = Merchant::factory()->create(['name' => 'Supermercado Toledo']);
        Promotion::factory()->create(['merchant_id' => $singular->id, 'promotion_category_id' => $supermercados->id]);

        app(MergeDuplicateMerchantsAction::class)->handle();

        $this->assertModelMissing($reversed);
        $this->assertModelMissing($singular);
        $this->assertSame(5, Promotion::where('merchant_id', $canonical->id)->count());
        $this->assertSame(1, Merchant::count());
    }

    /**
     * Regression test for a real incident found in the same scan: "El Sol"
     * and "Sol Supermercado" share only the ordinary Spanish word "sol"
     * (sun) — real promotion data shows them in unrelated categories
     * (Construcción vs. Supermercados), meaning they're genuinely different
     * businesses that merely happen to share a name, the exact failure mode
     * `MerchantWordMatcher`'s own docblock warns about. Must be left alone.
     */
    public function test_never_merges_generic_words_whose_real_categories_disagree(): void
    {
        $construccion = PromotionCategory::factory()->create(['name' => 'Construcción']);
        $supermercados = PromotionCategory::factory()->create(['name' => 'Supermercados']);
        $hardwareStore = Merchant::factory()->create(['name' => 'El Sol']);
        Promotion::factory()->create(['merchant_id' => $hardwareStore->id, 'promotion_category_id' => $construccion->id]);
        $supermarket = Merchant::factory()->create(['name' => 'Sol Supermercado']);
        Promotion::factory()->create(['merchant_id' => $supermarket->id, 'promotion_category_id' => $supermercados->id]);

        $merges = app(MergeDuplicateMerchantsAction::class)->handle();

        $this->assertSame([], $merges);
        $this->assertSame(2, Merchant::count());
    }

    /**
     * A merchant with no promotions at all contradicts nothing, so it's
     * still safe to fold into a canonical the other variants confirm.
     */
    public function test_merges_a_generic_core_duplicate_that_has_no_promotions_of_its_own(): void
    {
        $supermercados = PromotionCategory::factory()->create(['name' => 'Supermercados']);
        $canonical = Merchant::factory()->create(['name' => 'Coto']);
        Promotion::factory()->create(['merchant_id' => $canonical->id, 'promotion_category_id' => $supermercados->id]);
        $noData = Merchant::factory()->create(['name' => 'Supermercado Coto']);

        app(MergeDuplicateMerchantsAction::class)->handle();

        $this->assertModelMissing($noData);
        $this->assertSame(1, Merchant::count());
    }

    /**
     * A name made up entirely of generic filler, or of digits only, never
     * identifies a specific business — must never become a merge key.
     */
    public function test_never_groups_purely_generic_or_numeric_names(): void
    {
        Merchant::factory()->create(['name' => 'Supermercado']);
        Merchant::factory()->create(['name' => 'El Super']);
        Merchant::factory()->create(['name' => '49']);
        Merchant::factory()->create(['name' => 'Los 49']);

        $merges = app(MergeDuplicateMerchantsAction::class)->handle();

        $this->assertSame([], $merges);
        $this->assertSame(4, Merchant::count());
    }
}
