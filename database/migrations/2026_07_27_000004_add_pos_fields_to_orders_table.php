<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('channel', ['en_ligne', 'boutique'])->default('en_ligne');
            $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cash_session_id')->nullable()->constrained('cash_sessions')->nullOnDelete();
            $table->decimal('amount_received', 10, 2)->nullable();
            $table->decimal('discount_amount', 10, 2)->nullable()->default(0);
            $table->string('discount_reason')->nullable();
            $table->string('walk_in_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['cashier_id']);
            $table->dropForeign(['cash_session_id']);
            $table->dropColumn([
                'channel',
                'cashier_id',
                'cash_session_id',
                'amount_received',
                'discount_amount',
                'discount_reason',
                'walk_in_name',
            ]);
        });
    }
};
