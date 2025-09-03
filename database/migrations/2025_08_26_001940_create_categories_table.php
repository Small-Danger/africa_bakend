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
        Schema::create('categories', function (Blueprint $table) {
            $table->id();                                    // Identifiant unique auto-incrémenté
            $table->string('name');                          // Nom de la catégorie (ex: "Fruits", "Légumes")
            $table->string('slug')->unique();                // URL-friendly version du nom (ex: "fruits", "legumes")
            $table->text('description')->nullable();         // Description détaillée de la catégorie
            $table->string('image_main')->nullable();        // Chemin vers l'image principale de la catégorie
            $table->foreignId('parent_id')->nullable()->constrained('categories')->onDelete('cascade'); // ID de la catégorie parente (pour les sous-catégories)
            $table->boolean('is_active')->default(true);     // Si la catégorie est visible/active
            $table->integer('sort_order')->default(0);       // Ordre d'affichage de la catégorie
            $table->timestamps();                            // Dates de création et modification
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
