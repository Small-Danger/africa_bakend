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
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();                                    // Identifiant unique auto-incrémenté
            $table->foreignId('cart_session_id')->constrained('cart_sessions')->onDelete('cascade'); // ID de la session de panier
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade'); // ID du produit ajouté au panier
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->onDelete('cascade'); // ID de la variante sélectionnée (optionnel)
            $table->integer('quantity');                     // Quantité du produit dans le panier
            $table->timestamps();                            // Dates de création et modification
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
