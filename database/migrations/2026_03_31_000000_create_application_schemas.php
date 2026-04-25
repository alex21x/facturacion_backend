<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['master', 'core', 'sales', 'appcfg', 'inventory', 'ops'] as $schema) {
            DB::statement(sprintf('CREATE SCHEMA IF NOT EXISTS %s', $schema));
        }
    }

    public function down(): void
    {
        // Schemas may contain application data; do not drop them on rollback.
    }
};