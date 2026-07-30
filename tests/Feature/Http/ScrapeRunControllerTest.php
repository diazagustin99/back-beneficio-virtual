<?php

namespace Tests\Feature\Http;

use App\Models\ScrapeRun;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ScrapeRunControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_index_lists_scrape_runs_paginated(): void
    {
        ScrapeRun::factory()->count(3)->create();

        $this->getJson('/api/v1/scrape-runs')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data', 'message', 'current_page', 'total_pages', 'total_registros']);
    }

    public function test_index_filters_by_wallet_slug(): void
    {
        $walletA = Wallet::factory()->create(['slug' => 'mercado_pago']);
        $walletB = Wallet::factory()->create(['slug' => 'uala']);
        ScrapeRun::factory()->for($walletA)->create();
        ScrapeRun::factory()->for($walletB)->create();

        $this->getJson('/api/v1/scrape-runs?wallet=mercado_pago')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_index_filters_by_status(): void
    {
        ScrapeRun::factory()->success()->create();
        ScrapeRun::factory()->failed()->create();

        $this->getJson('/api/v1/scrape-runs?status=failed')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_show_returns_a_single_scrape_run(): void
    {
        $scrapeRun = ScrapeRun::factory()->create();

        $this->getJson("/api/v1/scrape-runs/{$scrapeRun->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $scrapeRun->id);
    }

    public function test_show_returns_404_for_a_missing_scrape_run(): void
    {
        $this->getJson('/api/v1/scrape-runs/999')
            ->assertNotFound();
    }
}
