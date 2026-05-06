<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (\Illuminate\Support\Facades\Schema::hasTable('auth.users') &&
            !\Illuminate\Support\Facades\Schema::hasColumn('auth.users', 'last_temp_password')) {
            \Illuminate\Support\Facades\DB::statement(
                'ALTER TABLE auth.users ADD COLUMN last_temp_password TEXT DEFAULT NULL'
            );
        }
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\Schema::hasColumn('auth.users', 'last_temp_password')) {
            \Illuminate\Support\Facades\DB::statement(
                'ALTER TABLE auth.users DROP COLUMN IF EXISTS last_temp_password'
            );
        }
    }
};
