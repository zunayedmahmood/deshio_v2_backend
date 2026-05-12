<?php

namespace Database\Seeders;

use App\Models\VariantOption;
use Illuminate\Database\Seeder;

class SizeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $sizes = [];

        // 1. Numeric sizes from 30 to 50
        for ($i = 30; $i <= 50; $i++) {
            $sizes[] = (string) $i;
        }

        // 2. Alphabetical sizes XS to XXXXL
        $alphaSizes = ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL', 'XXXXL'];
        $sizes = array_merge($sizes, $alphaSizes);

        // 3. Unique decimal sizes
        $decimalSizes = ['2.2', '2.4', '2.6', '2.8', '2.10'];
        $sizes = array_merge($sizes, $decimalSizes);

        foreach ($sizes as $index => $sizeValue) {
            VariantOption::updateOrCreate(
                [
                    'name' => 'Size',
                    'value' => $sizeValue,
                ],
                [
                    'type' => 'text',
                    'is_active' => true,
                    'sort_order' => $index,
                ]
            );
        }

        $this->command->info('Successfully seeded ' . count($sizes) . ' sizes into variant_options.');
    }
}
