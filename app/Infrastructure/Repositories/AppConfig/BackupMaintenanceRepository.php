<?php

namespace App\Infrastructure\Repositories\AppConfig;

use App\Application\DTOs\AppConfig\BackupCompanyDTO;
use Illuminate\Support\Facades\DB;

class BackupMaintenanceRepository
{
    public function findCompanyById(int $companyId): ?BackupCompanyDTO
    {
        $company = DB::table('core.companies')
            ->where('id', $companyId)
            ->first(['id', 'tax_id', 'legal_name']);

        return $company ? BackupCompanyDTO::fromRow($company) : null;
    }

    public function beginTransaction(): void
    {
        DB::beginTransaction();
    }

    public function executeUnprepared(string $sql): void
    {
        DB::unprepared($sql);
    }

    public function commit(): void
    {
        DB::commit();
    }

    public function rollBack(): void
    {
        DB::rollBack();
    }

    public function getPdo(): \PDO
    {
        return DB::connection()->getPdo();
    }

    public function cursorCompanyTableRows(string $schema, string $table, int $companyId): iterable
    {
        return DB::table("{$schema}.{$table}")
            ->where('company_id', $companyId)
            ->cursor();
    }

    public function cursorRawSelect(string $sql): iterable
    {
        return DB::cursor($sql);
    }

    public function fetchCompanyScopedTableRows(): array
    {
        return DB::select(
            "select c.table_schema, c.table_name
             from information_schema.columns c
             inner join information_schema.tables t
                 on t.table_schema = c.table_schema
                and t.table_name = c.table_name
             where c.column_name = 'company_id'
                 and c.table_schema not in ('pg_catalog', 'information_schema')
                 and t.table_type = 'BASE TABLE'
             group by c.table_schema, c.table_name
             order by table_schema, table_name"
        );
    }

    public function fetchForeignKeyTableRows(): array
    {
        return DB::select(
            "select
                n_child.nspname as child_schema,
                c_child.relname as child_table,
                n_parent.nspname as parent_schema,
                c_parent.relname as parent_table
             from pg_constraint con
             inner join pg_class c_child on c_child.oid = con.conrelid
             inner join pg_namespace n_child on n_child.oid = c_child.relnamespace
             inner join pg_class c_parent on c_parent.oid = con.confrelid
             inner join pg_namespace n_parent on n_parent.oid = c_parent.relnamespace
             where con.contype = 'f'"
        );
    }

    public function fetchForeignKeyColumnRows(): array
    {
        return DB::select(
            "select
                n_child.nspname as child_schema,
                c_child.relname as child_table,
                a_child.attname as child_column,
                n_parent.nspname as parent_schema,
                c_parent.relname as parent_table,
                a_parent.attname as parent_column
             from pg_constraint con
             inner join pg_class c_child on c_child.oid = con.conrelid
             inner join pg_namespace n_child on n_child.oid = c_child.relnamespace
             inner join pg_class c_parent on c_parent.oid = con.confrelid
             inner join pg_namespace n_parent on n_parent.oid = c_parent.relnamespace
             inner join pg_attribute a_child on a_child.attrelid = con.conrelid and a_child.attnum = con.conkey[1]
             inner join pg_attribute a_parent on a_parent.attrelid = con.confrelid and a_parent.attnum = con.confkey[1]
             where con.contype = 'f'
               and c_child.relkind = 'r'
               and c_parent.relkind = 'r'
               and array_length(con.conkey, 1) = 1
               and array_length(con.confkey, 1) = 1"
        );
    }
}
