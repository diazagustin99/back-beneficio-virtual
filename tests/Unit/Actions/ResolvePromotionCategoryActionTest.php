<?php

namespace Tests\Unit\Actions;

use App\Actions\Scraping\ResolvePromotionCategoryAction;
use App\Models\PromotionCategory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ResolvePromotionCategoryActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_creates_a_category_on_first_call(): void
    {
        $category = app(ResolvePromotionCategoryAction::class)->handle('Supermercados');

        $this->assertNotNull($category);
        $this->assertModelExists($category);
        $this->assertSame('supermercados', $category->slug);
    }

    public function test_returns_the_existing_category_on_a_repeat_call(): void
    {
        $action = app(ResolvePromotionCategoryAction::class);

        $first = $action->handle('Supermercados');
        $second = $action->handle('supermercados');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PromotionCategory::count());
    }

    public function test_null_name_returns_null_without_creating_a_row(): void
    {
        $category = app(ResolvePromotionCategoryAction::class)->handle(null);

        $this->assertNull($category);
        $this->assertSame(0, PromotionCategory::count());
    }

    public function test_a_known_variant_resolves_to_the_canonical_category_instead_of_a_duplicate(): void
    {
        config(['category_aliases.transportes' => 'Transporte']);

        $category = app(ResolvePromotionCategoryAction::class)->handle('Transportes');

        $this->assertSame('Transporte', $category->name);
        $this->assertSame('transporte', $category->slug);
        $this->assertSame(1, PromotionCategory::count());
    }

    public function test_the_variant_and_the_canonical_name_both_resolve_to_the_same_row(): void
    {
        config(['category_aliases.transportes' => 'Transporte']);
        $action = app(ResolvePromotionCategoryAction::class);

        $viaVariant = $action->handle('Transportes');
        $viaCanonical = $action->handle('Transporte');

        $this->assertSame($viaCanonical->id, $viaVariant->id);
        $this->assertSame(1, PromotionCategory::count());
    }

    public function test_a_shouting_name_without_an_alias_is_stored_in_title_case(): void
    {
        $category = app(ResolvePromotionCategoryAction::class)->handle('PESCA Y CAZA');

        $this->assertSame('Pesca y Caza', $category->name);
        $this->assertSame('pesca-y-caza', $category->slug);
    }

    public function test_a_single_word_shouting_name_is_title_cased(): void
    {
        $category = app(ResolvePromotionCategoryAction::class)->handle('MUEBLES');

        $this->assertSame('Muebles', $category->name);
    }

    public function test_a_mixed_case_name_is_never_altered(): void
    {
        $category = app(ResolvePromotionCategoryAction::class)->handle('Hogar y deco');

        $this->assertSame('Hogar y deco', $category->name);
    }

    public function test_an_alias_takes_priority_over_shouting_case_normalization(): void
    {
        config(['category_aliases.super' => 'Supermercados']);

        $category = app(ResolvePromotionCategoryAction::class)->handle('SUPER');

        $this->assertSame('Supermercados', $category->name);
    }

    /**
     * Real config entries, not faked, so this fails if
     * config/category_aliases.php regresses — mirrors the real rubros a
     * scraper brings under a different name than an existing category.
     * See plans/0025-categorias-duplicadas.md.
     */
    public function test_real_category_aliases_resolve_to_their_real_existing_categories(): void
    {
        $autos = PromotionCategory::factory()->create(['name' => 'Autos y motos', 'slug' => 'autos-y-motos']);
        $gastronomia = PromotionCategory::factory()->create(['name' => 'Gastronomía', 'slug' => 'gastronomia']);
        $moda = PromotionCategory::factory()->create(['name' => 'Moda y accesorios', 'slug' => 'moda-y-accesorios']);
        $action = app(ResolvePromotionCategoryAction::class);

        $this->assertSame($autos->id, $action->handle('Vehículos')->id);
        // Pirelli/Ducati (motos), not bicycles, despite the name.
        $this->assertSame($autos->id, $action->handle('Rodados')->id);
        $this->assertSame($gastronomia->id, $action->handle('Restaurantes')->id);
        $this->assertSame($moda->id, $action->handle('Indumentaria y Accesorios')->id);
        $this->assertSame(3, PromotionCategory::count());
    }

    /**
     * CategoryWordMatcher (last resort, after both alias and exact-name
     * lookups miss): a raw word that is a significant token of exactly one
     * existing category resolves to it instead of creating a near-duplicate.
     * See plans/0025-categorias-duplicadas.md.
     */
    public function test_a_word_that_is_part_of_exactly_one_existing_category_resolves_to_it(): void
    {
        $action = app(ResolvePromotionCategoryAction::class);
        $bodegas = PromotionCategory::factory()->create(['name' => 'Bodegas y Vinotecas', 'slug' => 'bodegas-y-vinotecas']);

        $resolved = $action->handle('Vinotecas');

        $this->assertSame($bodegas->id, $resolved->id);
        $this->assertSame(1, PromotionCategory::count());
    }

    /**
     * Same "don't guess" discipline as MerchantWordMatcher's own equivalent
     * test: a word that is a token of more than one existing category is
     * ambiguous, so it creates its own entry rather than picking one.
     */
    public function test_a_word_matching_more_than_one_existing_category_creates_its_own_entry_instead_of_guessing(): void
    {
        $action = app(ResolvePromotionCategoryAction::class);
        PromotionCategory::factory()->create(['name' => 'Salud y Belleza', 'slug' => 'salud-y-belleza']);
        PromotionCategory::factory()->create(['name' => 'Salud y Bienestar', 'slug' => 'salud-y-bienestar']);

        $resolved = $action->handle('Salud');

        $this->assertSame('Salud', $resolved->name);
        $this->assertSame(3, PromotionCategory::count());
    }

    /**
     * Regression test for CategoryWordMatcher's own exclusion list: a
     * category deliberately combining two distinct rubros (e.g. "Jugueterías
     * y Librerías") must never be used as a match target, even when a raw
     * word is one of its own two halves — that compound category doesn't
     * really describe a standalone "Librerías" business. Same lesson
     * MerchantWordMatcher already learned from the real "Repuestos"
     * incident.
     */
    public function test_a_word_from_a_deliberately_compound_category_never_matches_it(): void
    {
        $compound = PromotionCategory::factory()->create(['name' => 'Jugueterías y Librerías', 'slug' => 'jugueterias-y-librerias']);

        $resolved = app(ResolvePromotionCategoryAction::class)->handle('Librerias');

        $this->assertNotSame($compound->id, $resolved->id);
        $this->assertSame('Librerias', $resolved->name);
        $this->assertSame(2, PromotionCategory::count());
    }

    /**
     * The exclusion only protects the compound category from being used as
     * a match *target* — the same word can still resolve normally against a
     * different, unrelated standalone category.
     */
    public function test_a_word_from_an_excluded_compound_category_can_still_match_a_different_standalone_category(): void
    {
        $action = app(ResolvePromotionCategoryAction::class);
        PromotionCategory::factory()->create(['name' => 'Turismo y Entretenimiento', 'slug' => 'turismo-y-entretenimiento']);
        $standalone = PromotionCategory::factory()->create(['name' => 'Centros de Entretenimiento', 'slug' => 'centros-de-entretenimiento']);

        $resolved = $action->handle('Entretenimiento');

        $this->assertSame($standalone->id, $resolved->id);
        $this->assertSame(2, PromotionCategory::count());
    }
}
