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
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();                                    // Identifiant unique auto-incrémenté
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade'); // ID du produit parent
            $table->string('name');                          // Nom de la variante (ex: "500g", "1kg", "Rouge", "Vert")
            $table->string('sku')->nullable();               // Code SKU unique de la variante (ex: "POM-500G", "TOM-1KG")
            $table->decimal('price', 10, 2);                 // Prix spécifique de cette variante
            $table->integer('stock_quantity')->nullable();   // Quantité en stock (null = stock illimité)
            $table->boolean('is_active')->default(true);     // Si la variante est visible/active
            $table->integer('sort_order')->default(0);       // Ordre d'affichage de la variante
            $table->timestamps();                            // Dates de création et modification
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
