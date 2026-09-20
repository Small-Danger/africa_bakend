<?php

namespace App\Console\Commands;

use App\Services\StockService;
use Illuminate\Console\Command;

class ExpireStockReservations extends Command
{
    protected $signature = 'stock:expire-reservations';

    protected $description = 'Expire les commandes site non payées et libère le stock réservé';

    public function handle(StockService $stock): int
    {
        $count = $stock->expireOverdueReservations();
        $this->info($count === 0
            ? 'Aucune commande expirée'
            : $count.' commande(s) expirée(s), stock libéré');

        return self::SUCCESS;
    }
}
