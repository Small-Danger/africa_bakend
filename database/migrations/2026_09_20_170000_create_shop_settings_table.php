<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('preorder_delay_days')->default(14);
            $table->unsignedSmallInteger('unpaid_expiry_hours')->default(24);
            $table->unsignedSmallInteger('low_stock_threshold')->default(5);
            $table->unsignedTinyInteger('min_deposit_percent')->default(0);
            $table->json('payment_methods');
            $table->string('whatsapp_number', 30)->nullable();
            $table->boolean('notify_whatsapp')->default(true);
            $table->boolean('notify_email')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_settings');
    }
};
