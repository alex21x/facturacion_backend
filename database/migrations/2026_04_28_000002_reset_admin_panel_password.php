<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Resets the admin_panel superuser password to the known default.
 * Scoped exclusively to company_id = 1 (system company).
 * Does NOT touch users of any other company.
 *
 * Default credentials:
 *   username : admin_panel
 *   password : Admin1234!
 */
class ResetAdminPanelPassword extends Migration
{
    private const SYSTEM_COMPANY_ID = 1;
    private const ADMIN_USERNAME    = 'admin_panel';
    private const DEFAULT_PASSWORD  = 'Admin1234!';

    public function up(): void
    {
        $now = now()->toDateTimeString();

        DB::table('auth.users')
            ->where('username', self::ADMIN_USERNAME)
            ->where('company_id', self::SYSTEM_COMPANY_ID)
            ->update([
                'password_hash' => Hash::make(self::DEFAULT_PASSWORD),
                'status'        => 1,
                'updated_at'    => $now,
            ]);
    }

    public function down(): void
    {
        // No reversible action — password reset cannot be undone safely.
    }
}
