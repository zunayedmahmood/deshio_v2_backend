<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add floating replacement barcode support.
     *
     * A replacement barcode is an extra scan identity for one existing physical unit.
     * It does NOT increase product_batches.quantity. When the batch/location stock reaches
     * zero, any remaining sellable identities in the same pool are auto-voided by the service.
     */
    public function up(): void
    {
        Schema::table('product_barcodes', function (Blueprint $table) {
            if (!Schema::hasColumn('product_barcodes', 'is_replacement')) {
                $table->boolean('is_replacement')
                    ->default(false)
                    ->after('is_defective')
                    ->comment('True when this barcode is a temporary/floating relabel identity');
            }

            if (!Schema::hasColumn('product_barcodes', 'replacement_status')) {
                $table->string('replacement_status', 40)
                    ->nullable()
                    ->after('is_replacement')
                    ->comment('open, used, reconciled, cancelled for temporary relabel barcodes');
            }

            if (!Schema::hasColumn('product_barcodes', 'relabel_reason')) {
                $table->string('relabel_reason', 80)
                    ->nullable()
                    ->after('replacement_status')
                    ->comment('Reason for replacement barcode: lost_sticker, damaged_sticker, etc.');
            }

            if (!Schema::hasColumn('product_barcodes', 'relabel_metadata')) {
                $table->json('relabel_metadata')
                    ->nullable()
                    ->after('relabel_reason')
                    ->comment('Audit metadata for replacement barcode handling');
            }

            $table->index(['is_replacement']);
            $table->index(['replacement_status']);
            $table->index(['product_id', 'batch_id', 'current_store_id', 'is_replacement'], 'pb_relabel_pool_idx');
        });

        if (!Schema::hasTable('product_barcode_relabels')) {
            Schema::create('product_barcode_relabels', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('batch_id')->constrained('product_batches')->cascadeOnDelete();
                $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
                $table->foreignId('replacement_barcode_id')->constrained('product_barcodes')->cascadeOnDelete();
                $table->foreignId('known_original_barcode_id')->nullable()->constrained('product_barcodes')->nullOnDelete();
                $table->foreignId('reconciled_original_barcode_id')->nullable()->constrained('product_barcodes')->nullOnDelete();
                $table->string('status', 40)->default('open')->comment('open, used, reconciled, cancelled');
                $table->string('reason', 80)->default('lost_sticker');
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
                $table->timestamp('used_at')->nullable();
                $table->timestamp('reconciled_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['product_id', 'batch_id', 'store_id']);
                $table->index(['replacement_barcode_id']);
                $table->index(['status']);
                $table->index(['created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_barcode_relabels');

        Schema::table('product_barcodes', function (Blueprint $table) {
            if (Schema::hasColumn('product_barcodes', 'is_replacement')) {
                $table->dropIndex(['is_replacement']);
            }
            if (Schema::hasColumn('product_barcodes', 'replacement_status')) {
                $table->dropIndex(['replacement_status']);
            }
            $table->dropIndex('pb_relabel_pool_idx');
            $table->dropColumn([
                'is_replacement',
                'replacement_status',
                'relabel_reason',
                'relabel_metadata',
            ]);
        });
    }
};
