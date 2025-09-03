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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();                                    // Identifiant unique auto-incrémenté (numéro de commande)
            $table->foreignId('client_id')->constrained('users')->onDelete('cascade'); // ID du client qui a passé la commande
            $table->decimal('total_amount', 10, 2);          // Montant total de la commande
            $table->enum('status', ['en_attente', 'acceptée', 'prête', 'en_cours', 'disponible', 'annulée'])->default('en_attente'); // Statut de la commande
            $table->string('whatsapp_message_id')->nullable(); // ID du message WhatsApp envoyé (pour le suivi)
            $table->text('notes')->nullable();               // Notes additionnelles sur la commande
            $table->timestamps();                            // Dates de création et modification
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
