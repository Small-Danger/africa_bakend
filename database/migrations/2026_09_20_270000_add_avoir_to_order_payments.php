<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE order_payments DROP CONSTRAINT IF EXISTS order_payments_method_check');
        DB::statement("ALTER TABLE order_payments ADD CONSTRAINT order_payments_method_check CHECK (((method)::text = ANY ((ARRAY['especes'::character varying, 'carte'::character varying, 'orange_money'::character varying, 'wave'::character varying, 'depot'::character varying, 'avoir'::character varying])::text[])))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::table('order_payments')->where('method', 'avoir')->delete();
        DB::statement('ALTER TABLE order_payments DROP CONSTRAINT IF EXISTS order_payments_method_check');
        DB::statement("ALTER TABLE order_payments ADD CONSTRAINT order_payments_method_check CHECK (((method)::text = ANY ((ARRAY['especes'::character varying, 'carte'::character varying, 'orange_money'::character varying, 'wave'::character varying, 'depot'::character varying])::text[])))");
    }
};
