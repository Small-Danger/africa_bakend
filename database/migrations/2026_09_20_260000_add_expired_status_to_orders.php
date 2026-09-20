<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->applyStatusCheck([
            'en_attente',
            'acceptée',
            'prête',
            'en_cours',
            'disponible',
            'annulée',
            'expirée',
        ]);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::table('orders')->where('status', 'expirée')->update(['status' => 'annulée']);
        }

        $this->applyStatusCheck([
            'en_attente',
            'acceptée',
            'prête',
            'en_cours',
            'disponible',
            'annulée',
        ]);
    }

    /**
     * @param  list<string>  $statuses
     */
    private function applyStatusCheck(array $statuses): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $quoted = implode(', ', array_map(
                fn (string $status) => "'".$status."'::character varying",
                $statuses
            ));

            DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check');
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (((status)::text = ANY ((ARRAY[{$quoted}])::text[])))");

            return;
        }

        if ($driver === 'mysql') {
            $quoted = implode(',', array_map(
                fn (string $status) => "'".$status."'",
                $statuses
            ));
            DB::statement("ALTER TABLE orders MODIFY status ENUM({$quoted}) NOT NULL DEFAULT 'en_attente'");
        }
    }
};
