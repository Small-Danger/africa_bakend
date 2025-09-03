<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$admin = App\Models\User::where('role', 'admin')->first();
if ($admin) {
    $token = $admin->createToken('test-token')->plainTextToken;
    echo "Token: " . $token . "\n";
    echo "Admin: " . $admin->name . " (" . $admin->email . ")\n";
} else {
    echo "Aucun admin trouvé\n";
}
