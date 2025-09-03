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
        Schema::create('users', function (Blueprint $table) {
            $table->id();                                    // Identifiant unique auto-incrémenté
            $table->string('name');                          // Nom complet de l'utilisateur
            $table->string('email')->unique();               // Email unique pour la connexion
            $table->timestamp('email_verified_at')->nullable(); // Date de vérification de l'email
            $table->string('password');                      // Mot de passe hashé
            $table->string('whatsapp_phone')->nullable();    // Numéro WhatsApp pour les commandes
            $table->enum('role', ['admin', 'client'])->default('client'); // Rôle utilisateur (admin ou client)
            $table->boolean('is_active')->default(true);     // Si le compte utilisateur est actif/bloqué
            $table->rememberToken();                         // Token "Se souvenir de moi"
            $table->timestamps();                            // Dates de création et modification
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();              // Email pour la réinitialisation
            $table->string('token');                         // Token de réinitialisation
            $table->timestamp('created_at')->nullable();     // Date de création du token
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();                 // ID de session unique
            $table->foreignId('user_id')->nullable()->index(); // ID de l'utilisateur connecté
            $table->string('ip_address', 45)->nullable();   // Adresse IP de l'utilisateur
            $table->text('user_agent')->nullable();         // Navigateur/appareil de l'utilisateur
            $table->longText('payload');                    // Données de session
            $table->integer('last_activity')->index();      // Timestamp de la dernière activité
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
