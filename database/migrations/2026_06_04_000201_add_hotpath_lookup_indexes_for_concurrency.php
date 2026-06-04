<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddHotpathLookupIndexesForConcurrency extends Migration
{
    public function up(): void
    {
        // Branch-level feature toggle resolution:
        // where company_id = ? and branch_id = ? and feature_code = ?
        $this->createIndexIfColumns(
            'appcfg',
            'branch_feature_toggles',
            'idx_branch_feature_toggles_company_branch_code',
            ['company_id', 'branch_id', 'feature_code']
        );

        // Operational context warehouse list:
        // where company_id = ? and status = 1 and (branch_id = ? or branch_id is null) order by name
        $this->createIndexIfColumns(
            'inventory',
            'warehouses',
            'idx_warehouses_company_status_branch_name',
            ['company_id', 'status', 'branch_id', 'name']
        );

        // Operational context cash register list:
        // where company_id = ? and status = 1 and branch/warehouse scope order by name
        $this->createIndexIfColumns(
            'sales',
            'cash_registers',
            'idx_cash_registers_company_status_branch_wh_name',
            ['company_id', 'status', 'branch_id', 'warehouse_id', 'name']
        );

        // Fallback for environments where name is absent on warehouses.
        $this->createIndexIfColumns(
            'inventory',
            'warehouses',
            'idx_warehouses_company_status_branch',
            ['company_id', 'status', 'branch_id']
        );

        // Fallback for environments where name is absent on cash_registers.
        $this->createIndexIfColumns(
            'sales',
            'cash_registers',
            'idx_cash_registers_company_status_branch_wh',
            ['company_id', 'status', 'branch_id', 'warehouse_id']
        );
    }

    public function down(): void
    {
        $this->dropIndexIfExists('appcfg', 'idx_branch_feature_toggles_company_branch_code');
        $this->dropIndexIfExists('inventory', 'idx_warehouses_company_status_branch_name');
        $this->dropIndexIfExists('inventory', 'idx_warehouses_company_status_branch');
        $this->dropIndexIfExists('sales', 'idx_cash_registers_company_status_branch_wh_name');
        $this->dropIndexIfExists('sales', 'idx_cash_registers_company_status_branch_wh');
    }

    private function createIndexIfColumns(string $schema, string $table, string $indexName, array $columns): void
    {
        if (!$this->tableExists($schema, $table)) {
            return;
        }

        foreach ($columns as $column) {
            if (!$this->columnExists($schema, $table, $column)) {
                return;
            }
        }

        DB::statement(sprintf(
            'CREATE INDEX IF NOT EXISTS %s ON %s.%s (%s)',
            $indexName,
            $schema,
            $table,
            implode(', ', $columns)
        ));
    }

    private function dropIndexIfExists(string $schema, string $indexName): void
    {
        DB::statement(sprintf('DROP INDEX IF EXISTS %s.%s', $schema, $indexName));
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
            ->where('column_name', $column)
            ->exists();
    }
}
