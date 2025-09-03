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
        Schema::create('cart_sessions', function (Blueprint $table) {
            $table->id();                                    // Identifiant unique auto-incrémenté
            $table->string('session_id')->unique();           // ID de session unique pour le panier (stocké côté client)
            $table->foreignId('client_id')->nullable()->constrained('users')->onDelete('cascade'); // ID de l'utilisateur connecté (optionnel)
            $table->timestamp('expires_at');                  // Date d'expiration de la session de panier
            $table->timestamps();                            // Dates de création et modification
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cart_sessions');
    }
};
