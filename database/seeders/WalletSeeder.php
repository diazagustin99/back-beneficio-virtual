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
