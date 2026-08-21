<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assembly_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_item_id')->constrained('bom_items')->cascadeOnDelete();
            $table->foreignId('paint_record_id')->nullable()->constrained('paint_records')->cascadeOnDelete();
            $table->enum('side', ['RH', 'LH', 'COMMON'])->index();
            $table->integer('quantity');
            $table->foreignId('assembled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assembly_records');
    }
};
