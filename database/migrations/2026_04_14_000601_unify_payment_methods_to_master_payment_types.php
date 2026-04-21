<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE IF EXISTS sales.cash_movements DROP CONSTRAINT IF EXISTS cash_movements_payment_method_id_fkey');
        DB::statement('ALTER TABLE IF EXISTS sales.commercial_document_payments DROP CONSTRAINT IF EXISTS commercial_document_payments_payment_method_id_fkey');
        DB::statement('ALTER TABLE IF EXISTS sales.commercial_documents DROP CONSTRAINT IF EXISTS commercial_documents_payment_method_id_fkey');
        DB::statement('ALTER TABLE IF EXISTS sales.sales_order_payments DROP CONSTRAINT IF EXISTS sales_order_payments_payment_method_id_fkey');
        DB::statement('ALTER TABLE IF EXISTS sales.sales_orders DROP CONSTRAINT IF EXISTS sales_orders_payment_method_id_fkey');

        DB::statement(<<<'SQL'
DO $$
DECLARE
    src RECORD;
    target_id INTEGER;
    next_id INTEGER;
BEGIN
    IF to_regclass('core.payment_methods') IS NULL OR to_regclass('master.payment_types') IS NULL THEN
        RETURN;
    END IF;

    FOR src IN
        SELECT id, code, name, status
        FROM core.payment_methods
        ORDER BY id
    LOOP
        SELECT pt.id
        INTO target_id
        FROM master.payment_types pt
        WHERE LOWER(TRIM(pt.name)) = LOWER(TRIM(src.name))
        ORDER BY pt.id
        LIMIT 1;

        IF target_id IS NULL THEN
            SELECT COALESCE(MAX(id), 0) + 1 INTO next_id FROM master.payment_types;
            target_id := next_id;

            INSERT INTO master.payment_types (id, name, comment, is_active, status)
            VALUES (
                target_id,
                src.name,
                src.code,
                CASE WHEN src.status = 1 THEN 1 ELSE 0 END,
                CASE WHEN src.status = 1 THEN 1 ELSE 0 END
            );
        ELSE
            UPDATE master.payment_types
            SET comment = COALESCE(NULLIF(TRIM(comment), ''), src.code),
                is_active = CASE WHEN src.status = 1 THEN 1 ELSE is_active END,
                status = CASE
                    WHEN src.status = 1 AND status NOT IN (1, 2) THEN 1
                    WHEN src.status = 1 THEN status
                    ELSE status
                END
            WHERE id = target_id;
        END IF;

        IF to_regclass('sales.commercial_documents') IS NOT NULL THEN
            UPDATE sales.commercial_documents
            SET payment_method_id = target_id
            WHERE payment_method_id = src.id;
        END IF;

        IF to_regclass('sales.commercial_document_payments') IS NOT NULL THEN
            UPDATE sales.commercial_document_payments
            SET payment_method_id = target_id
            WHERE payment_method_id = src.id;
        END IF;

        IF to_regclass('sales.sales_orders') IS NOT NULL THEN
            UPDATE sales.sales_orders
            SET payment_method_id = target_id
            WHERE payment_method_id = src.id;
        END IF;

        IF to_regclass('sales.sales_order_payments') IS NOT NULL THEN
            UPDATE sales.sales_order_payments
            SET payment_method_id = target_id
            WHERE payment_method_id = src.id;
        END IF;

        IF to_regclass('sales.cash_movements') IS NOT NULL THEN
            UPDATE sales.cash_movements
            SET payment_method_id = target_id
            WHERE payment_method_id = src.id;
        END IF;

        IF to_regclass('inventory.stock_entries') IS NOT NULL THEN
            UPDATE inventory.stock_entries
            SET payment_method_id = target_id
            WHERE payment_method_id = src.id;
        END IF;
    END LOOP;
END $$;
SQL
);

        DB::statement('ALTER TABLE IF EXISTS sales.cash_movements ADD CONSTRAINT cash_movements_payment_method_id_fkey FOREIGN KEY (payment_method_id) REFERENCES master.payment_types(id)');
        DB::statement('ALTER TABLE IF EXISTS sales.commercial_document_payments ADD CONSTRAINT commercial_document_payments_payment_method_id_fkey FOREIGN KEY (payment_method_id) REFERENCES master.payment_types(id)');
        DB::statement('ALTER TABLE IF EXISTS sales.commercial_documents ADD CONSTRAINT commercial_documents_payment_method_id_fkey FOREIGN KEY (payment_method_id) REFERENCES master.payment_types(id)');
        DB::statement('ALTER TABLE IF EXISTS sales.sales_order_payments ADD CONSTRAINT sales_order_payments_payment_method_id_fkey FOREIGN KEY (payment_method_id) REFERENCES master.payment_types(id)');
        DB::statement('ALTER TABLE IF EXISTS sales.sales_orders ADD CONSTRAINT sales_orders_payment_method_id_fkey FOREIGN KEY (payment_method_id) REFERENCES master.payment_types(id)');

        DB::statement('DROP TABLE IF EXISTS core.payment_methods');
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE IF NOT EXISTS core.payment_methods (
    id BIGINT NOT NULL PRIMARY KEY,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(100) NOT NULL,
    status SMALLINT NOT NULL DEFAULT 1
)
SQL
);

        DB::statement('CREATE SEQUENCE IF NOT EXISTS core.payment_methods_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1');
        DB::statement('ALTER SEQUENCE core.payment_methods_id_seq OWNED BY core.payment_methods.id');
        DB::statement("ALTER TABLE core.payment_methods ALTER COLUMN id SET DEFAULT nextval('core.payment_methods_id_seq')");

        DB::statement(<<<'SQL'
INSERT INTO core.payment_methods (id, code, name, status)
SELECT
    pt.id,
    COALESCE(NULLIF(TRIM(pt.comment), ''), CONCAT('PM', pt.id::text)) as code,
    pt.name,
    CASE WHEN COALESCE(pt.is_active, 0) = 1 OR COALESCE(pt.status, 0) IN (1, 2) THEN 1 ELSE 0 END as status
FROM master.payment_types pt
ON CONFLICT (id) DO UPDATE SET
    code = EXCLUDED.code,
    name = EXCLUDED.name,
    status = EXCLUDED.status
SQL
);

        DB::statement('ALTER TABLE IF EXISTS sales.cash_movements DROP CONSTRAINT IF EXISTS cash_movements_payment_method_id_fkey');
        DB::statement('ALTER TABLE IF EXISTS sales.commercial_document_payments DROP CONSTRAINT IF EXISTS commercial_document_payments_payment_method_id_fkey');
        DB::statement('ALTER TABLE IF EXISTS sales.commercial_documents DROP CONSTRAINT IF EXISTS commercial_documents_payment_method_id_fkey');
        DB::statement('ALTER TABLE IF EXISTS sales.sales_order_payments DROP CONSTRAINT IF EXISTS sales_order_payments_payment_method_id_fkey');
        DB::statement('ALTER TABLE IF EXISTS sales.sales_orders DROP CONSTRAINT IF EXISTS sales_orders_payment_method_id_fkey');

        DB::statement('ALTER TABLE IF EXISTS sales.cash_movements ADD CONSTRAINT cash_movements_payment_method_id_fkey FOREIGN KEY (payment_method_id) REFERENCES core.payment_methods(id)');
        DB::statement('ALTER TABLE IF EXISTS sales.commercial_document_payments ADD CONSTRAINT commercial_document_payments_payment_method_id_fkey FOREIGN KEY (payment_method_id) REFERENCES core.payment_methods(id)');
        DB::statement('ALTER TABLE IF EXISTS sales.commercial_documents ADD CONSTRAINT commercial_documents_payment_method_id_fkey FOREIGN KEY (payment_method_id) REFERENCES core.payment_methods(id)');
        DB::statement('ALTER TABLE IF EXISTS sales.sales_order_payments ADD CONSTRAINT sales_order_payments_payment_method_id_fkey FOREIGN KEY (payment_method_id) REFERENCES core.payment_methods(id)');
        DB::statement('ALTER TABLE IF EXISTS sales.sales_orders ADD CONSTRAINT sales_orders_payment_method_id_fkey FOREIGN KEY (payment_method_id) REFERENCES core.payment_methods(id)');
    }
};
