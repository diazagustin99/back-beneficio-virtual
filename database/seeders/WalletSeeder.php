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
            'BNA',
            'Brubank',
        ];

        foreach ($wallets as $name) {
            Wallet::query()->updateOrCreate(
                ['slug' => Str::slug($name, '_')],
                ['name' => $name, 'is_active' => true],
            );
        }
    }
}
