<?php

namespace App\Console\Commands;

use App\Services\StockService;
use Illuminate\Console\Command;

class ExpireStockReservations extends Command
{
    protected $signature = 'stock:expire-reservations';

    protected $description = 'Libère les réservations de stock non payées dont le délai est dépassé';

    public function handle(StockService $stock): int
    {
        $count = $stock->expireOverdueReservations();
        $this->info($count === 0
            ? 'Aucune réservation expirée'
            : $count.' commande(s) expirée(s), stock libéré');

        return self::SUCCESS;
    }
}
