<?php

namespace Database\Seeders;

use App\Models\PromotionCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PromotionCategorySeeder extends Seeder
{
    /**
     * Starter taxonomy. Scrapers may introduce further categories on the fly
     * via ResolvePromotionCategoryAction — this list is a seed, not a cap.
     */
    public function run(): void
    {
        $categories = [
            'Supermercados',
            'Gastronomía',
            'Indumentaria',
            'Combustible',
            'Farmacias y Salud',
            'Electrodomésticos y Tecnología',
            'Entretenimiento',
            'Turismo y Viajes',
            'Servicios',
            'Otros',
        ];

        foreach ($categories as $name) {
            PromotionCategory::query()->updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name],
            );
        }
    }
}
