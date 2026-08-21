<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained('receipts')->cascadeOnDelete();
            $table->foreignId('bom_item_id')->constrained('bom_items')->cascadeOnDelete();
            $table->enum('side', ['RH', 'LH', 'COMMON'])->index();
            $table->integer('received_quantity');
            $table->enum('status', [
                'received',
                'sent_to_qc',
                'qc_received',
                'qc_approved',
                'qc_rejected',
                'qc_rework',
                'qc_inspected',
                'paint_completed',
                'assembly_completed',
                'reverted',
                'scrapped',
                'returned_to_store',
                'returned_to_vendor'
            ])->default('received')->index();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_items');
    }
};
