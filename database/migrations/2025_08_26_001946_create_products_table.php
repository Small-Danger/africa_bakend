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
        Schema::create('products', function (Blueprint $table) {
            $table->id();                                    // Identifiant unique auto-incrémenté
            $table->string('name');                          // Nom du produit (ex: "Pommes Golden", "Tomates Cerises")
            $table->string('slug')->unique();                // URL-friendly version du nom (ex: "pommes-golden", "tomates-cerises")
            $table->text('description');                     // Description détaillée du produit
            $table->decimal('base_price', 10, 2)->nullable(); // Prix de base du produit (peut être remplacé par les variantes)
            $table->foreignId('category_id')->constrained('categories')->onDelete('cascade'); // ID de la catégorie du produit
            $table->string('image_main')->nullable();        // Image principale du produit (chemin ou données binaires)
            $table->boolean('is_active')->default(true);     // Si le produit est visible/actif
            $table->integer('sort_order')->default(0);       // Ordre d'affichage du produit dans sa catégorie
            $table->timestamps();                            // Dates de création et modification
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
