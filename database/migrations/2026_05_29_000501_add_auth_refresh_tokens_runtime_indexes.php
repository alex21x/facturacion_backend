<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!$this->tableExists('auth', 'refresh_tokens')) {
            return;
        }

        if ($this->hasColumns('auth', 'refresh_tokens', ['id', 'user_id', 'expires_at', 'revoked_at'])) {
            DB::statement(
                'CREATE INDEX IF NOT EXISTS idx_refresh_tokens_auth_claims_active '
                . 'ON auth.refresh_tokens (id, user_id, expires_at) '
                . 'WHERE revoked_at IS NULL'
            );
        }

        if ($this->hasColumns('auth', 'refresh_tokens', ['token_hash', 'expires_at', 'revoked_at'])) {
            DB::statement(
                'CREATE INDEX IF NOT EXISTS idx_refresh_tokens_token_active '
                . 'ON auth.refresh_tokens (token_hash, expires_at) '
                . 'WHERE revoked_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS auth.idx_refresh_tokens_auth_claims_active');
        DB::statement('DROP INDEX IF EXISTS auth.idx_refresh_tokens_token_active');
    }

    private function tableExists(string $schema, string $table): bool
    {
        return DB::table('information_schema.tables')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->exists();
    }

    private function hasColumns(string $schema, string $table, array $columns): bool
    {
        $existing = DB::table('information_schema.columns')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->whereIn('column_name', $columns)
            ->pluck('column_name')
            ->map(static fn ($value) => (string) $value)
            ->all();

        foreach ($columns as $column) {
            if (!in_array($column, $existing, true)) {
                return false;
            }
        }

        return true;
    }
};
