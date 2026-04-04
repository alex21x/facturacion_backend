<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class CreateDetraccionServiceCodesTable extends Migration
{
    public function up()
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS master.detraccion_service_codes (
                id SERIAL PRIMARY KEY,
                code CHARACTER VARYING(4) NOT NULL,
                name CHARACTER VARYING(300) NOT NULL,
                rate_percent NUMERIC(5,2) NOT NULL DEFAULT 10.00,
                is_active SMALLINT NOT NULL DEFAULT 1,
                CONSTRAINT detraccion_service_codes_code_unique UNIQUE (code)
            )
        ");

        $rows = [
            ['001', 'Azúcar y melaza de caña',                                                              10.00],
            ['003', 'Alcohol etílico',                                                                       10.00],
            ['004', 'Recursos hidrobiológicos',                                                              4.00],
            ['005', 'Maíz amarillo duro',                                                                   4.00],
            ['006', 'Arena y piedra',                                                                        10.00],
            ['007', 'Residuos, subproductos, desechos, recortes y desperdicios',                             15.00],
            ['009', 'Carnes y despojos comestibles',                                                         4.00],
            ['010', 'Harina, polvo y pellets de pescado, crustáceos y demás invertebrados acuáticos',        4.00],
            ['011', 'Madera',                                                                                4.00],
            ['016', 'Aceite de pescado',                                                                     10.00],
            ['019', 'Minerales metálicos no auríferos',                                                      10.00],
            ['020', 'Bienes inmuebles gravados con IGV',                                                     4.00],
            ['021', 'Oro y demás minerales metálicos auríferos y plata',                                     10.00],
            ['022', 'Minerales no metálicos',                                                               10.00],
            ['023', 'Leche',                                                                                 4.00],
            ['024', 'Tabaco en rama',                                                                        10.00],
            ['026', 'Intermediación laboral y tercerización',                                                12.00],
            ['030', 'Contratos de construcción',                                                             4.00],
            ['031', 'Fabricación de bienes por encargo',                                                    10.00],
            ['034', 'Arrendamiento de bienes muebles',                                                      10.00],
            ['035', 'Mantenimiento y reparación de bienes muebles',                                         12.00],
            ['036', 'Movimiento de carga',                                                                   10.00],
            ['037', 'Otros servicios empresariales',                                                        12.00],
            ['039', 'Actividades de servicios relacionadas con la minería',                                  10.00],
            ['040', 'Comisión mercantil',                                                                    12.00],
            ['041', 'Servicio de fabricación de bienes a partir de insumos del cliente',                     10.00],
            ['042', 'Otros servicios gravados con el IGV',                                                   12.00],
            ['043', 'Transporte ferroviario de pasajeros',                                                   10.00],
            ['044', 'Actividades de agencias de aduana',                                                    10.00],
            ['045', 'Actividades de agencias de viaje',                                                     12.00],
            ['047', 'Demás servicios gravados con el IGV',                                                   12.00],
        ];

        foreach ($rows as [$code, $name, $rate]) {
            DB::statement(
                "INSERT INTO master.detraccion_service_codes (code, name, rate_percent, is_active)
                 VALUES (?, ?, ?, 1)
                 ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, rate_percent = EXCLUDED.rate_percent",
                [$code, $name, $rate]
            );
        }
    }

    public function down()
    {
        DB::statement('DROP TABLE IF EXISTS master.detraccion_service_codes');
    }
}
