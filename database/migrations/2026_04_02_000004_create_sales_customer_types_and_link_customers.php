<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE TABLE IF NOT EXISTS sales.customer_types (
                id bigserial PRIMARY KEY,
                name varchar(120) NOT NULL,
                sunat_code integer NOT NULL,
                sunat_abbr varchar(120) NULL,
                is_active boolean NOT NULL DEFAULT true,
                created_at timestamp with time zone NOT NULL DEFAULT now(),
                updated_at timestamp with time zone NOT NULL DEFAULT now(),
                CONSTRAINT customer_types_sunat_code_unique UNIQUE (sunat_code)
            )'
        );

        if (DB::table('information_schema.tables')
            ->where('table_schema', 'core')
            ->where('table_name', 'tipo_clientes')
            ->exists()) {
            DB::statement(
                "INSERT INTO sales.customer_types (name, sunat_code, sunat_abbr, is_active, created_at, updated_at)
                 SELECT tc.tipo_cliente, tc.codigo, tc.abr_standar, tc.activo, now(), now()
                 FROM core.tipo_clientes tc
                 ON CONFLICT (sunat_code) DO UPDATE
                 SET name = EXCLUDED.name,
                     sunat_abbr = EXCLUDED.sunat_abbr,
                     is_active = EXCLUDED.is_active,
                     updated_at = now()"
            );
        }

        DB::statement("INSERT INTO sales.customer_types (name, sunat_code, sunat_abbr, is_active, created_at, updated_at)
            VALUES
            ('Persona Natural', 1, 'DOC.NACIONAL DE IDEN', true, now(), now()),
            ('Persona Juridica', 6, 'REG. UNICO DE CONTRI', true, now(), now()),
            ('Empresas Del Extranjero', 0, 'DOC.TRIB.NO.DOM.SIN', true, now(), now()),
            ('Carnet de Extranjeria', 4, 'CARNET DE EXTRANJERIA', true, now(), now()),
            ('Pasaporte', 7, 'PASAPORTE', true, now(), now()),
            ('Otros', 8, 'OTROS', true, now(), now())
            ON CONFLICT (sunat_code) DO NOTHING");

        DB::statement('ALTER TABLE sales.customers ADD COLUMN IF NOT EXISTS customer_type_id bigint NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS customers_customer_type_id_idx ON sales.customers (customer_type_id)');

        DB::statement(
            "DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM information_schema.table_constraints
                    WHERE constraint_schema = 'sales'
                      AND table_name = 'customers'
                      AND constraint_name = 'customers_customer_type_id_fkey'
                ) THEN
                    ALTER TABLE sales.customers
                    ADD CONSTRAINT customers_customer_type_id_fkey
                    FOREIGN KEY (customer_type_id)
                    REFERENCES sales.customer_types(id);
                END IF;
            END$$;"
        );

        if (DB::table('information_schema.columns')
            ->where('table_schema', 'sales')
            ->where('table_name', 'customers')
            ->where('column_name', 'tipo_cliente_codigo')
            ->exists()) {
            DB::statement(
                'UPDATE sales.customers c
                 SET customer_type_id = ct.id
                 FROM sales.customer_types ct
                 WHERE c.customer_type_id IS NULL
                   AND c.tipo_cliente_codigo = ct.sunat_code'
            );
        }

        DB::statement(
            "UPDATE sales.customers c
             SET customer_type_id = ct.id
             FROM sales.customer_types ct
             WHERE c.customer_type_id IS NULL
               AND c.doc_type ~ '^[0-9]+$'
               AND CAST(c.doc_type AS integer) = ct.sunat_code"
        );

        DB::statement(
            "UPDATE sales.customers c
             SET customer_type_id = ct.id
             FROM sales.customer_types ct
             WHERE c.customer_type_id IS NULL
               AND (
                    UPPER(TRIM(c.doc_type)) = UPPER(TRIM(ct.name))
                    OR UPPER(TRIM(c.doc_type)) = UPPER(TRIM(COALESCE(ct.sunat_abbr, '')))
               )"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sales.customers DROP CONSTRAINT IF EXISTS customers_customer_type_id_fkey');
        DB::statement('DROP INDEX IF EXISTS sales.customers_customer_type_id_idx');
        DB::statement('ALTER TABLE sales.customers DROP COLUMN IF EXISTS customer_type_id');
        DB::statement('DROP TABLE IF EXISTS sales.customer_types');
    }
};
