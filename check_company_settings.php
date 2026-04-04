<?php
require 'vendor/autoload.php';
require 'bootstrap/app.php';

use Illuminate\Support\Facades\DB;

$settings = DB::table('core.company_settings')
    ->where('company_id', 1)
    ->select('address', 'phone', 'email', 'extra_data')
    ->first();

if ($settings) {
    echo "=== Company Settings (company_id=1) ===\n";
    echo "Address: " . ($settings->address ?? '(empty)') . "\n";
    echo "Phone: " . ($settings->phone ?? '(empty)') . "\n";
    echo "Email: " . ($settings->email ?? '(empty)') . "\n";
    echo "Extra Data: " . ($settings->extra_data ?? '(empty)') . "\n\n";
    
    if ($settings->extra_data) {
        $extra = json_decode($settings->extra_data, true);
        echo "Extra Data decoded:\n";
        echo json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    }
} else {
    echo "No company settings found for company_id=1\n";
}
