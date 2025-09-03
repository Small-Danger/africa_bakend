<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();                                    // Identifiant unique auto-incrémenté
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade'); // ID de la commande parente
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade'); // ID du produit commandé
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->onDelete('cascade'); // ID de la variante sélectionnée (optionnel)
            $table->integer('quantity');                     // Quantité commandée
            $table->decimal('unit_price', 10, 2);            // Prix unitaire au moment de la commande
            $table->decimal('total_price', 10, 2);           // Prix total (quantity * unit_price)
            $table->timestamps();                            // Dates de création et modification
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
