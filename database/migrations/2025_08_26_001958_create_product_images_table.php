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
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();                                    // Identifiant unique auto-incrémenté
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade'); // ID du produit parent
            $table->string('media_path');                    // Chemin vers le fichier média (image ou vidéo)
            $table->enum('media_type', ['image', 'video'])->default('image'); // Type de média (image ou vidéo)
            $table->string('alt_text')->nullable();          // Texte alternatif pour l'accessibilité
            $table->string('title')->nullable();             // Titre du média (tooltip)
            $table->integer('sort_order')->default(0);       // Ordre d'affichage des médias
            $table->timestamps();                            // Dates de création et modification
            
            // Index pour optimiser les requêtes par produit et type
            $table->index(['product_id', 'media_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
