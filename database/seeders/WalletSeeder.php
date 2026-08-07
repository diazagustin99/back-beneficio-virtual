<?php

namespace Database\Seeders;

use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class WalletSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $wallets = [
            'Mercado Pago',
            'Ualá',
            'Personal Pay',
            'Naranja X',
            'Cuenta DNI',
            'MODO',
            // Display name is the recognizable bank name, not the acronym —
            // the slug stays `bna` on purpose (already tied to ~340 scraped
            // promotions and every scraper/test that references this wallet).
            ['slug' => 'bna', 'name' => 'Banco Nación'],
            'Brubank',
            'Macro',
            'Prex',
            'ICBC',
            // Display name is the recognizable bank name; slug stays
            // `galicia` on purpose, matching GaliciaScraper::walletSlug().
            ['slug' => 'galicia', 'name' => 'Banco Galicia'],
            // Slug stays `santander` on purpose, matching
            // SantanderScraper::walletSlug().
            ['slug' => 'santander', 'name' => 'Santander Río'],
            ['slug' => 'credicoop', 'name' => 'Banco Credicoop'],
            ['slug' => 'banco_ciudad', 'name' => 'Banco Ciudad'],
            // Slug stays `supervielle` on purpose, matching
            // SupervielleScraper::walletSlug().
            ['slug' => 'supervielle', 'name' => 'Banco Supervielle'],

            // Attribution-only wallets — no scraper of their own (no entry
            // in config/scrapers.php), only ever receiving a promotion
            // ModoScraper confirms is exclusive to that bank (see
            // ModoScraper::BANK_WALLET_SLUGS). Slugs match that map exactly.
            ['slug' => 'bbva', 'name' => 'BBVA'],
            ['slug' => 'comafi', 'name' => 'Banco Comafi'],
            ['slug' => 'banco_santa_fe', 'name' => 'Banco Santa Fe'],
            ['slug' => 'bancor', 'name' => 'BANCOR'],
            ['slug' => 'banco_entre_rios', 'name' => 'Banco Entre Ríos'],
            ['slug' => 'banco_santa_cruz', 'name' => 'Banco Santa Cruz'],
            ['slug' => 'banco_san_juan', 'name' => 'Banco San Juan'],
            ['slug' => 'banco_corrientes', 'name' => 'Banco Corrientes'],
            ['slug' => 'yoy', 'name' => 'YOY'],
        ];

        foreach ($wallets as $wallet) {
            $slug = is_array($wallet) ? $wallet['slug'] : Str::slug($wallet, '_');
            $name = is_array($wallet) ? $wallet['name'] : $wallet;

            Wallet::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'is_active' => true],
            );
        }
    }
}
