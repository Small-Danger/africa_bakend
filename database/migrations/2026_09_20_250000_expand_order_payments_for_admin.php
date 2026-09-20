<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_payments', function (Blueprint $table) {
            $table->string('reference', 120)->nullable()->after('amount');
            $table->string('note', 500)->nullable()->after('reference');
            $table->foreignId('recorded_by')->nullable()->after('note')->constrained('users')->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE order_payments DROP CONSTRAINT IF EXISTS order_payments_method_check');
            DB::statement("ALTER TABLE order_payments ADD CONSTRAINT order_payments_method_check CHECK (((method)::text = ANY ((ARRAY['especes'::character varying, 'carte'::character varying, 'orange_money'::character varying, 'wave'::character varying, 'depot'::character varying])::text[])))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE order_payments DROP CONSTRAINT IF EXISTS order_payments_method_check');
            DB::statement("ALTER TABLE order_payments ADD CONSTRAINT order_payments_method_check CHECK (((method)::text = ANY ((ARRAY['especes'::character varying, 'carte'::character varying, 'orange_money'::character varying, 'wave'::character varying])::text[])))");
        }

        Schema::table('order_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recorded_by');
            $table->dropColumn(['reference', 'note']);
        });
    }
};
