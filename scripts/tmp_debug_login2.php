<?php
define('LARAVEL_START', microtime(true));
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// Replicate EXACTLY what AuthController does
$username = 'admin_panel';
$password = 'Admin1234!';

$userQuery = DB::table('auth.users as u')
    ->join('core.companies as c', 'c.id', '=', 'u.company_id')
    ->select('u.id', 'u.company_id', 'u.branch_id', 'u.username', 'u.password_hash', 'u.first_name', 'u.last_name', 'u.email', 'u.status', DB::raw('c.status as company_status'))
    ->where('u.username', $username)
    ->where('u.status', 1);

$user = $userQuery->first();

echo "User found: " . ($user ? "YES" : "NO") . "\n";
if ($user) {
    echo "company_status: {$user->company_status}\n";
    echo "Hash check: " . (Hash::check($password, $user->password_hash) ? "TRUE ✓" : "FALSE ✗") . "\n";
    echo "password_hash stored: {$user->password_hash}\n";
} else {
    echo "Query returned NULL\n";
    // Check without join
    $u = DB::table('auth.users')->where('username', $username)->first();
    echo "User in auth.users: " . json_encode($u) . "\n";
}
