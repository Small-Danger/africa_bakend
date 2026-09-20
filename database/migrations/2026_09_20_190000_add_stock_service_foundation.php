<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('preorder_allowed')->default(true)->after('is_active');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedInteger('reserved_quantity')->default(0)->after('stock_quantity');
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity');
            $table->unsignedInteger('quantity_after');
            $table->string('type', 40);
            $table->string('channel', 20)->default('admin');
            $table->string('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('reference');
            $table->json('properties')->nullable();
            $table->timestamps();

            $table->index(['product_variant_id', 'created_at']);
            $table->index(['type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('reserved_quantity');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('preorder_allowed');
        });
    }
};
