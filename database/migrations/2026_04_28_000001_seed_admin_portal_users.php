<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Ensures the admin-portal superuser (admin_panel) exists and is registered
 * in appcfg.admin_portal_users so the admin portal login works.
 *
 * Rules:
 *  - Only operates on company_id = 1 (system company / superadmin scope).
 *  - Does NOT touch users of any other company.
 *  - If admin_panel user already exists, password is left untouched.
 *  - If admin_panel user does NOT exist it is created with the default credentials.
 *  - The appcfg.admin_portal_users registration is idempotent (ON CONFLICT DO NOTHING).
 *
 * Default credentials (change after first login):
 *   username : admin_panel
 *   password : Admin1234!
 */
class SeedAdminPortalUsers extends Migration
{
    private const SYSTEM_COMPANY_ID = 1;
    private const ADMIN_USERNAME    = 'admin_panel';
    private const DEFAULT_PASSWORD  = 'Admin1234!';

    public function up(): void
    {
        $now = now()->toDateTimeString();

        // 1. Ensure the admin_portal_users table exists (mirrors AuthController logic).
        DB::statement(
            'CREATE TABLE IF NOT EXISTS appcfg.admin_portal_users (
                user_id    BIGINT PRIMARY KEY,
                status     SMALLINT NOT NULL DEFAULT 1,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            )'
        );

        // 2. Look up the admin_panel user restricted to the system company only.
        $user = DB::table('auth.users')
            ->where('username', self::ADMIN_USERNAME)
            ->where('company_id', self::SYSTEM_COMPANY_ID)
            ->first(['id']);

        if (!$user) {
            // 3a. User does not exist — create it on company_id = 1 only.
            //     We look up a branch that belongs to company 1; fall back to NULL.
            $branch = DB::table('core.branches')
                ->where('company_id', self::SYSTEM_COMPANY_ID)
                ->orderBy('id')
                ->first(['id']);

            $userId = DB::table('auth.users')->insertGetId([
                'company_id'    => self::SYSTEM_COMPANY_ID,
                'branch_id'     => $branch ? $branch->id : null,
                'username'      => self::ADMIN_USERNAME,
                'password_hash' => Hash::make(self::DEFAULT_PASSWORD),
                'first_name'    => 'Portal',
                'last_name'     => 'Admin',
                'email'         => 'admin.panel@demo.local',
                'status'        => 1,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        } else {
            // 3b. User already exists — do NOT change password or any other field.
            $userId = $user->id;
        }

        // 4. Register in admin_portal_users (superadmin whitelist).
        DB::statement(
            'INSERT INTO appcfg.admin_portal_users (user_id, status, created_at, updated_at)
             VALUES (?, 1, ?, ?)
             ON CONFLICT (user_id) DO NOTHING',
            [$userId, $now, $now]
        );
    }

    public function down(): void
    {
        // Remove from admin_portal_users whitelist only.
        // Do not delete the user itself to avoid data-loss on rollback.
        $user = DB::table('auth.users')
            ->where('username', self::ADMIN_USERNAME)
            ->where('company_id', self::SYSTEM_COMPANY_ID)
            ->first(['id']);

        if ($user) {
            DB::table('appcfg.admin_portal_users')
                ->where('user_id', $user->id)
                ->delete();
        }
    }
}
