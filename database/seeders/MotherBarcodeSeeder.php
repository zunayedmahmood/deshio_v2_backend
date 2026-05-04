<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;
use App\Models\ProductBatch;
use Illuminate\Support\Facades\DB;

class MotherBarcodeSeeder extends Seeder
{
    public function run(): void
    {
        $products = Product::all();
        $count = 0;

        foreach ($products as $product) {
            // Try to get existing primary barcode using DB table directly
            $primaryBarcode = DB::table('product_barcodes')
                ->where('product_id', $product->id)
                ->where('is_primary', true)
                ->first();
            
            $barcodeValue = $primaryBarcode ? $primaryBarcode->barcode : Product::generateUniqueBarcode();

            // Update product
            $product->update(['barcode' => $barcodeValue]);

            // Update all batches for this product
            ProductBatch::where('product_id', $product->id)
                ->update(['mother_barcode' => $barcodeValue]);

            $count++;
        }

        $this->command->info("Generated mother barcodes for {$count} products.");
    }
}
