<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE TABLE IF NOT EXISTS appcfg.verticals (
                id BIGSERIAL PRIMARY KEY,
                code VARCHAR(50) NOT NULL UNIQUE,
                name VARCHAR(120) NOT NULL,
                description TEXT NULL,
                status SMALLINT NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW()
            )'
        );

        DB::statement(
            'CREATE TABLE IF NOT EXISTS appcfg.company_verticals (
                id BIGSERIAL PRIMARY KEY,
                company_id BIGINT NOT NULL,
                vertical_id BIGINT NOT NULL,
                is_primary BOOLEAN NOT NULL DEFAULT FALSE,
                status SMALLINT NOT NULL DEFAULT 1,
                effective_from DATE NULL,
                effective_to DATE NULL,
                created_by BIGINT NULL,
                updated_by BIGINT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
                CONSTRAINT fk_company_verticals_company FOREIGN KEY (company_id) REFERENCES core.companies(id),
                CONSTRAINT fk_company_verticals_vertical FOREIGN KEY (vertical_id) REFERENCES appcfg.verticals(id),
                CONSTRAINT uq_company_vertical UNIQUE (company_id, vertical_id)
            )'
        );

        DB::statement(
            'CREATE TABLE IF NOT EXISTS appcfg.vertical_feature_templates (
                id BIGSERIAL PRIMARY KEY,
                vertical_id BIGINT NOT NULL,
                feature_code VARCHAR(120) NOT NULL,
                is_enabled BOOLEAN NOT NULL DEFAULT FALSE,
                config JSONB NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
                CONSTRAINT fk_vertical_feature_templates_vertical FOREIGN KEY (vertical_id) REFERENCES appcfg.verticals(id),
                CONSTRAINT uq_vertical_feature_template UNIQUE (vertical_id, feature_code)
            )'
        );

        DB::statement(
            'CREATE TABLE IF NOT EXISTS appcfg.company_vertical_feature_overrides (
                id BIGSERIAL PRIMARY KEY,
                company_id BIGINT NOT NULL,
                vertical_id BIGINT NOT NULL,
                feature_code VARCHAR(120) NOT NULL,
                is_enabled BOOLEAN NULL,
                config JSONB NULL,
                created_by BIGINT NULL,
                updated_by BIGINT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
                CONSTRAINT fk_company_vertical_feature_company FOREIGN KEY (company_id) REFERENCES core.companies(id),
                CONSTRAINT fk_company_vertical_feature_vertical FOREIGN KEY (vertical_id) REFERENCES appcfg.verticals(id),
                CONSTRAINT uq_company_vertical_feature UNIQUE (company_id, vertical_id, feature_code)
            )'
        );

        DB::statement(
            'CREATE TABLE IF NOT EXISTS appcfg.vertical_workflows (
                id BIGSERIAL PRIMARY KEY,
                vertical_id BIGINT NOT NULL,
                process_code VARCHAR(120) NOT NULL,
                version INT NOT NULL DEFAULT 1,
                states JSONB NOT NULL,
                transitions JSONB NOT NULL,
                status SMALLINT NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
                CONSTRAINT fk_vertical_workflows_vertical FOREIGN KEY (vertical_id) REFERENCES appcfg.verticals(id),
                CONSTRAINT uq_vertical_workflow UNIQUE (vertical_id, process_code, version)
            )'
        );

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_company_primary_vertical ON appcfg.company_verticals (company_id) WHERE is_primary = TRUE AND status = 1');

        DB::statement("INSERT INTO appcfg.verticals (code, name, description, status)
            VALUES
                ('RETAIL', 'Retail', 'Venta mostrador con inventario y caja', 1),
                ('SERVICES', 'Servicios', 'Facturacion por servicios con flujo simplificado de inventario', 1),
                ('RESTAURANT', 'Restaurante', 'Venta por mesa, salon y cocina', 1),
                ('WORKSHOP', 'Taller', 'Ordenes de trabajo y repuestos', 1)
            ON CONFLICT (code) DO UPDATE SET
                name = EXCLUDED.name,
                description = EXCLUDED.description,
                status = EXCLUDED.status,
                updated_at = NOW()");

        DB::statement("INSERT INTO appcfg.vertical_feature_templates (vertical_id, feature_code, is_enabled, config)
            SELECT v.id, x.feature_code, x.is_enabled, x.config::jsonb
            FROM appcfg.verticals v
            JOIN (
                VALUES
                    ('RETAIL', 'SALES_TAX_BRIDGE', true, '{}'),
                    ('RETAIL', 'SALES_SELLER_TO_CASHIER', false, '{}'),
                    ('RETAIL', 'PRODUCT_WHOLESALE_PRICING', true, '{}'),
                    ('SERVICES', 'SALES_TAX_BRIDGE', true, '{}'),
                    ('SERVICES', 'SALES_SELLER_TO_CASHIER', false, '{}'),
                    ('SERVICES', 'PRODUCT_WHOLESALE_PRICING', false, '{}'),
                    ('RESTAURANT', 'SALES_TAX_BRIDGE', true, '{}'),
                    ('RESTAURANT', 'SALES_SELLER_TO_CASHIER', false, '{}'),
                    ('RESTAURANT', 'PRODUCT_WHOLESALE_PRICING', false, '{}'),
                    ('WORKSHOP', 'SALES_TAX_BRIDGE', true, '{}'),
                    ('WORKSHOP', 'SALES_SELLER_TO_CASHIER', true, '{}'),
                    ('WORKSHOP', 'PRODUCT_WHOLESALE_PRICING', true, '{}')
            ) AS x(vertical_code, feature_code, is_enabled, config)
              ON x.vertical_code = v.code
            ON CONFLICT (vertical_id, feature_code) DO UPDATE SET
                is_enabled = EXCLUDED.is_enabled,
                config = EXCLUDED.config,
                updated_at = NOW()");

        $companyIds = DB::table('core.companies')->pluck('id')->all();
        if (!empty($companyIds)) {
            $retailId = DB::table('appcfg.verticals')->where('code', 'RETAIL')->value('id');
            if ($retailId) {
                $now = now();
                foreach ($companyIds as $companyId) {
                    $hasPrimaryVertical = DB::table('appcfg.company_verticals')
                        ->where('company_id', (int) $companyId)
                        ->where('is_primary', true)
                        ->where('status', 1)
                        ->exists();

                    if ($hasPrimaryVertical) {
                        continue;
                    }

                    DB::table('appcfg.company_verticals')->updateOrInsert(
                        [
                            'company_id' => (int) $companyId,
                            'vertical_id' => (int) $retailId,
                        ],
                        [
                            'is_primary' => true,
                            'status' => 1,
                            'effective_from' => $now->toDateString(),
                            'updated_at' => $now,
                            'created_at' => $now,
                        ]
                    );
                }
            }
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_company_primary_vertical');
        DB::statement('DROP TABLE IF EXISTS appcfg.vertical_workflows');
        DB::statement('DROP TABLE IF EXISTS appcfg.company_vertical_feature_overrides');
        DB::statement('DROP TABLE IF EXISTS appcfg.vertical_feature_templates');
        DB::statement('DROP TABLE IF EXISTS appcfg.company_verticals');
        DB::statement('DROP TABLE IF EXISTS appcfg.verticals');
    }
};
