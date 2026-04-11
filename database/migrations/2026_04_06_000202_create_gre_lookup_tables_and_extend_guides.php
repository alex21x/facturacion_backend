<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE TABLE IF NOT EXISTS sales.gre_guide_types (
                code varchar(20) PRIMARY KEY,
                sunat_code varchar(4) NOT NULL,
                name varchar(120) NOT NULL,
                is_enabled boolean NOT NULL DEFAULT true,
                sort_order int NOT NULL DEFAULT 0
            )'
        );

        DB::statement(
            'CREATE TABLE IF NOT EXISTS sales.gre_transfer_reasons (
                code varchar(4) PRIMARY KEY,
                name varchar(180) NOT NULL,
                is_enabled boolean NOT NULL DEFAULT true,
                sort_order int NOT NULL DEFAULT 0
            )'
        );

        DB::statement(
            'CREATE TABLE IF NOT EXISTS sales.gre_transport_modes (
                code varchar(4) PRIMARY KEY,
                name varchar(120) NOT NULL,
                is_enabled boolean NOT NULL DEFAULT true,
                sort_order int NOT NULL DEFAULT 0
            )'
        );

        DB::statement("INSERT INTO sales.gre_guide_types (code, sunat_code, name, is_enabled, sort_order)
            VALUES
            ('REMITENTE', '01', 'Guia de remitente', true, 1),
            ('TRANSPORTISTA', '02', 'Guia de transportista', false, 2)
            ON CONFLICT (code) DO UPDATE SET
            sunat_code = EXCLUDED.sunat_code,
            name = EXCLUDED.name,
            is_enabled = EXCLUDED.is_enabled,
            sort_order = EXCLUDED.sort_order");

        DB::statement("INSERT INTO sales.gre_transport_modes (code, name, is_enabled, sort_order)
            VALUES
            ('01', 'Transporte publico', true, 1),
            ('02', 'Transporte privado', true, 2)
            ON CONFLICT (code) DO UPDATE SET
            name = EXCLUDED.name,
            is_enabled = EXCLUDED.is_enabled,
            sort_order = EXCLUDED.sort_order");

        DB::statement("INSERT INTO sales.gre_transfer_reasons (code, name, is_enabled, sort_order)
            VALUES
            ('01', 'Venta', true, 1),
            ('02', 'Compra', true, 2),
            ('04', 'Traslado entre establecimientos de la misma empresa', true, 3),
            ('08', 'Importacion', true, 4),
            ('09', 'Exportacion', true, 5),
            ('13', 'Otros', true, 6),
            ('14', 'Venta sujeta a confirmacion del comprador', true, 7),
            ('18', 'Traslado emisor itinerante CP', true, 8),
            ('19', 'Traslado a zona primaria', true, 9)
            ON CONFLICT (code) DO UPDATE SET
            name = EXCLUDED.name,
            is_enabled = EXCLUDED.is_enabled,
            sort_order = EXCLUDED.sort_order");

        DB::statement("ALTER TABLE sales.gre_guides ADD COLUMN IF NOT EXISTS transport_mode_code varchar(4) NOT NULL DEFAULT '02'");
        DB::statement("ALTER TABLE sales.gre_guides ADD COLUMN IF NOT EXISTS partida_ubigeo varchar(6)");
        DB::statement("ALTER TABLE sales.gre_guides ADD COLUMN IF NOT EXISTS llegada_ubigeo varchar(6)");
        DB::statement("ALTER TABLE sales.gre_guides ADD COLUMN IF NOT EXISTS related_document_id bigint");

        DB::statement('CREATE INDEX IF NOT EXISTS gre_guides_related_document_idx ON sales.gre_guides (related_document_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS gre_guides_related_document_idx');
        DB::statement('ALTER TABLE sales.gre_guides DROP COLUMN IF EXISTS related_document_id');
        DB::statement('ALTER TABLE sales.gre_guides DROP COLUMN IF EXISTS llegada_ubigeo');
        DB::statement('ALTER TABLE sales.gre_guides DROP COLUMN IF EXISTS partida_ubigeo');
        DB::statement('ALTER TABLE sales.gre_guides DROP COLUMN IF EXISTS transport_mode_code');
        DB::statement('DROP TABLE IF EXISTS sales.gre_transport_modes');
        DB::statement('DROP TABLE IF EXISTS sales.gre_transfer_reasons');
        DB::statement('DROP TABLE IF EXISTS sales.gre_guide_types');
    }
};
