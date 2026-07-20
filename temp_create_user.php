<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;

try {
    User::create([
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'testcreate2@example.com',
        'password' => Hash::make('password'),
        'role' => 'nurse',
        'account_status' => 'active',
        'user_type' => 'staff',
        'department' => 'General',
        'staff_position' => null,
        'specialization' => null,
        'online_status' => 'offline',
    ]);
    echo "created\n";
} catch (Throwable $e) {
    echo $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
