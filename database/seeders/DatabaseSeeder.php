<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // Créer un utilisateur admin
        User::create([
            'name' => 'Admin',
            'email' => 'admin@bsshop.com',
            'password' => Hash::make('BS04034242'),
            'role' => 'admin',
            'whatsapp_phone' => '+22663126849',
            'is_active' => true, // Si le compte administrateur est actif
        ]);

    }
}
