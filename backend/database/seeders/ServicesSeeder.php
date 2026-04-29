<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ServicesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $services = [
            [
                'service_code' => 'DRY-WASH-001',
                'name' => 'Dry Wash',
                'description' => 'Professional dry cleaning service for clothes and fabrics',
                'category' => 'laundry',
                'base_price' => 150.00,
                'pricing_type' => 'per_unit',
                'estimated_duration' => 1440, // 24 hours
                'unit' => 'kg',
                'min_quantity' => 1,
                'max_quantity' => 50,
                'is_active' => true,
                'requires_approval' => false,
                'is_featured' => true,
                'options' => [
                    'express' => ['label' => 'Express Service (12 hours)', 'price_modifier' => 50],
                    'stain_removal' => ['label' => 'Extra Stain Removal', 'price_modifier' => 100],
                    'folding' => ['label' => 'Professional Folding', 'price_modifier' => 20],
                ],
                'requirements' => [
                    'no_food_stains' => 'Please remove food stains before delivery',
                    'zipper_closed' => 'Ensure all zippers are closed',
                ],
                'instructions' => 'Please separate light and dark colors. Remove all items from pockets.',
                'sort_order' => 1,
            ],
            [
                'service_code' => 'IRON-001',
                'name' => 'Ironing Service',
                'description' => 'Professional ironing and pressing service',
                'category' => 'laundry',
                'base_price' => 50.00,
                'pricing_type' => 'per_unit',
                'estimated_duration' => 120, // 2 hours
                'unit' => 'piece',
                'min_quantity' => 1,
                'max_quantity' => 20,
                'is_active' => true,
                'requires_approval' => false,
                'is_featured' => true,
                'options' => [
                    'steam_iron' => ['label' => 'Steam Ironing', 'price_modifier' => 20],
                    'delicate_fabrics' => ['label' => 'Delicate Fabrics', 'price_modifier' => 30],
                ],
                'requirements' => [
                    'fabric_type' => 'Please specify fabric type (cotton, silk, etc.)',
                ],
                'instructions' => 'Hang clothes properly to avoid wrinkles during transport.',
                'sort_order' => 2,
            ],
            [
                'service_code' => 'WASH-FOLD-001',
                'name' => 'Wash & Fold',
                'description' => 'Complete laundry service including washing, drying, and folding',
                'category' => 'laundry',
                'base_price' => 100.00,
                'pricing_type' => 'per_unit',
                'estimated_duration' => 720, // 12 hours
                'unit' => 'kg',
                'min_quantity' => 2,
                'max_quantity' => 30,
                'is_active' => true,
                'requires_approval' => false,
                'is_featured' => true,
                'options' => [
                    'detergent_choice' => ['label' => 'Premium Detergent', 'price_modifier' => 25],
                    'fabric_softener' => ['label' => 'Fabric Softener', 'price_modifier' => 15],
                    'express' => ['label' => 'Express Service (6 hours)', 'price_modifier' => 75],
                ],
                'requirements' => [
                    'separate_colors' => 'Please separate white and colored clothes',
                    'no_delicates' => 'Remove delicate items that cannot be machine washed',
                ],
                'instructions' => 'Use mesh bags for small items. Remove all accessories.',
                'sort_order' => 3,
            ],
            [
                'service_code' => 'CARPET-CLEAN-001',
                'name' => 'Carpet Cleaning',
                'description' => 'Deep cleaning service for carpets and rugs',
                'category' => 'cleaning',
                'base_price' => 500.00,
                'pricing_type' => 'per_unit',
                'estimated_duration' => 480, // 8 hours
                'unit' => 'sqft',
                'min_quantity' => 50,
                'max_quantity' => 1000,
                'is_active' => true,
                'requires_approval' => true,
                'is_featured' => false,
                'options' => [
                    'stain_treatment' => ['label' => 'Extra Stain Treatment', 'price_modifier' => 200],
                    'deodorizing' => ['label' => 'Deodorizing Treatment', 'price_modifier' => 150],
                ],
                'requirements' => [
                    'carpet_type' => 'Please specify carpet material and age',
                    'pet_hair' => 'Mention if there is pet hair or dander',
                ],
                'instructions' => 'Vacuum carpet before service. Move furniture if possible.',
                'sort_order' => 4,
            ],
            [
                'service_code' => 'SHOE-REPAIR-001',
                'name' => 'Shoe Repair',
                'description' => 'Professional shoe repair and restoration service',
                'category' => 'repair',
                'base_price' => 200.00,
                'pricing_type' => 'custom',
                'estimated_duration' => 2880, // 48 hours
                'unit' => 'pair',
                'min_quantity' => 1,
                'max_quantity' => 5,
                'is_active' => true,
                'requires_approval' => true,
                'is_featured' => false,
                'options' => [
                    'express' => ['label' => 'Express Repair (24 hours)', 'price_modifier' => 100],
                    'polish' => ['label' => 'Professional Polish', 'price_modifier' => 50],
                    'resole' => ['label' => 'Complete Resoling', 'price_modifier' => 300],
                ],
                'requirements' => [
                    'damage_description' => 'Please describe the damage in detail',
                    'shoe_brand' => 'Mention shoe brand and material',
                ],
                'instructions' => 'Bring both shoes for pair matching. Clean shoes before delivery.',
                'sort_order' => 5,
            ],
            [
                'service_code' => 'TAILORING-001',
                'name' => 'Tailoring Service',
                'description' => 'Custom tailoring and alteration service',
                'category' => 'repair',
                'base_price' => 300.00,
                'pricing_type' => 'custom',
                'estimated_duration' => 4320, // 72 hours
                'unit' => 'piece',
                'min_quantity' => 1,
                'max_quantity' => 3,
                'is_active' => true,
                'requires_approval' => true,
                'is_featured' => false,
                'options' => [
                    'express' => ['label' => 'Express Service (24 hours)', 'price_modifier' => 200],
                    'fabric_provided' => ['label' => 'Customer provides fabric', 'price_modifier' => 0],
                    'measurements' => ['label' => 'Professional measurements included', 'price_modifier' => 100],
                ],
                'requirements' => [
                    'measurements' => 'Current measurements required',
                    'style_preference' => 'Describe desired style and fit',
                ],
                'instructions' => 'Bring reference photos. Be clear about measurements and style preferences.',
                'sort_order' => 6,
            ],
        ];

        foreach ($services as $serviceData) {
            Service::create($serviceData);
        }
    }
}