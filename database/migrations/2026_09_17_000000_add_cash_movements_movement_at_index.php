<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// GET /api/cash/movements orders by cm.movement_at, but the only existing composite
// index (idx_cash_movements_company_session_created) covers created_at, not movement_at.
// That mismatch forces Postgres to sort the full filtered set instead of an index scan,
// which is the main driver behind the ~16s slow_request entries seen in Railway logs.
class AddCashMovementsMovementAtIndex extends Migration
{
    public function up(): void
    {
        if (!$this->tableExists('sales', 'cash_movements')) {
            return;
        }

        if (
            $this->columnExists('sales', 'cash_movements', 'COMPANY_ID')
            && $this->columnExists('sales', 'cash_movements', 'CASH_SESSION_ID')
            && $this->columnExists('sales', 'cash_movements', 'MOVEMENT_AT')
        ) {
            DB::statement(
                'CREATE INDEX IF NOT EXISTS idx_cash_movements_company_session_movement_at '
                . 'ON sales.cash_movements (company_id, cash_session_id, movement_at DESC)'
            );
        }

        if (
            $this->columnExists('sales', 'cash_movements', 'COMPANY_ID')
            && $this->columnExists('sales', 'cash_movements', 'CASH_REGISTER_ID')
            && $this->columnExists('sales', 'cash_movements', 'MOVEMENT_AT')
        ) {
            DB::statement(
                'CREATE INDEX IF NOT EXISTS idx_cash_movements_company_register_movement_at '
                . 'ON sales.cash_movements (company_id, cash_register_id, movement_at DESC)'
            );
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS sales.idx_cash_movements_company_session_movement_at');
        DB::statement('DROP INDEX IF EXISTS sales.idx_cash_movements_company_register_movement_at');
    }

    private function tableExists(string $schema, string $table): bool
    {
        return DB::table('information_schema.tables')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->exists();
    }

    private function columnExists(string $schema, string $table, string $column): bool
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->whereRaw('UPPER(column_name) = ?', [$column])
            ->exists();
    }
}
