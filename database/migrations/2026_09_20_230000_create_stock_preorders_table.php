<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_preorders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('original_quantity');
            $table->string('status', 20)->default('waiting');
            $table->timestamp('expires_at');
            $table->timestamp('allocated_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['product_variant_id', 'status', 'created_at']);
            $table->index(['order_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('walk_in_phone', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('walk_in_phone');
        });

        Schema::dropIfExists('stock_preorders');
    }
};
