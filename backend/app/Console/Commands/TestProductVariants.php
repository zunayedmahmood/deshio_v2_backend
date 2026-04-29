<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Category;
use Illuminate\Support\Facades\DB;

class TestProductVariants extends Command
{
    protected $signature = 'test:product-variants';
    protected $description = 'Test product variant creation - diagnose the issue PM reported';

    public function handle()
    {
        $this->info('🧪 Testing Product Variant Creation');
        $this->newLine();

        DB::beginTransaction();
        
        try {
            // 1. Create category
            $category = Category::create([
                'name' => 'Test Category ' . time(),
                'title' => 'Test Category ' . time(),
                'slug' => 'test-cat-' . time(),
                'is_active' => true
            ]);
            $this->info("✅ Created category: {$category->name}");

            // 2. Create ONE base product
            $this->newLine();
            $this->info('📦 Creating ONE base product...');
            
            $product = Product::create([
                'category_id' => $category->id,
                'sku' => 'SHIRT-001',
                'name' => 'Test Shirt',
                'description' => 'A test shirt with multiple sizes',
                'is_archived' => false
            ]);

            $this->info("   Product ID: {$product->id}");
            $this->info("   Product Name: {$product->name}");
            $this->info("   SKU: {$product->sku}");

            // 3. Create variants (1 color × 6 sizes = 6 variants)
            $this->newLine();
            $this->info('🎨 Creating 6 variants (1 color × 6 sizes)...');
            
            $color = 'Blue';
            $sizes = ['S', 'M', 'L', 'XL', 'XXL', 'XXXL'];
            
            $variants = [];
            foreach ($sizes as $index => $size) {
                $variant = ProductVariant::create([
                    'product_id' => $product->id,
                    'sku' => "{$product->sku}-{$color}-{$size}",
                    'attributes' => [
                        'color' => $color,
                        'size' => $size
                    ],
                    'price_adjustment' => 0,
                    'stock_quantity' => 10,
                    'is_active' => true,
                    'is_default' => ($index === 0)
                ]);
                
                $variants[] = $variant;
                $this->info("   ✅ Created variant: {$variant->sku} (Color: {$color}, Size: {$size})");
            }

            // 4. Query the product with variants
            $this->newLine();
            $this->info('🔍 Querying product with variants...');
            
            $productWithVariants = Product::with('variants')->find($product->id);
            
            $this->info("   Product Name: {$productWithVariants->name}");
            $this->info("   Total Variants: {$productWithVariants->variants->count()}");
            
            foreach ($productWithVariants->variants as $v) {
                $attrs = json_encode($v->attributes);
                $this->info("      - Variant: {$v->sku} | Attributes: {$attrs} | Stock: {$v->stock_quantity}");
            }

            // 5. Check for the WRONG pattern (multiple products with same SKU)
            $this->newLine();
            $this->info('⚠️  Checking for INCORRECT pattern (multiple products with same base SKU)...');
            
            $duplicateSKUProducts = Product::where('sku', 'like', 'SHIRT-001%')->get();
            
            if ($duplicateSKUProducts->count() > 1) {
                $this->error("   ❌ PROBLEM FOUND: {$duplicateSKUProducts->count()} products have similar SKUs!");
                foreach ($duplicateSKUProducts as $p) {
                    $this->error("      - Product ID: {$p->id}, Name: {$p->name}, SKU: {$p->sku}");
                }
                $this->newLine();
                $this->error("   This is WRONG! Frontend should create 1 product and attach variants to it.");
            } else {
                $this->info("   ✅ Correct! Only 1 product found with SKU: {$product->sku}");
            }

            // 6. Summary
            $this->newLine();
            $this->info('📊 SUMMARY:');
            $this->info("   ✅ Created 1 product");
            $this->info("   ✅ Created 6 variants for that product");
            $this->info("   ✅ All variants are linked to the same product_id: {$product->id}");
            
            $this->newLine();
            $this->info('🎯 CORRECT API FLOW:');
            $this->comment('   1. POST /api/products - Create base product');
            $this->comment('   2. POST /api/products/{productId}/variants/generate-matrix - Generate all variants');
            $this->comment('   OR');
            $this->comment('   2. POST /api/products/{productId}/variants - Create variants one by one');
            
            $this->newLine();
            $this->error('❌ WRONG FLOW (what PM described):');
            $this->comment('   - Calling POST /api/products 6 times = 6 separate products ❌');
            $this->comment('   - Each product has only 1 size variant ❌');

            DB::rollBack();
            $this->newLine();
            $this->info('🔄 Test data rolled back (not committed to database)');

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("❌ Test failed: " . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }

        return 0;
    }
}
