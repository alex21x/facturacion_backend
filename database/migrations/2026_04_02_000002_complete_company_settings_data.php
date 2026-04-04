<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $companyId = 1;

        $existingSettings = DB::table('core.company_settings')
            ->where('company_id', $companyId)
            ->first();

        if ($existingSettings) {
            $currentExtra = json_decode((string) ($existingSettings->extra_data ?? '{}'), true);
            if (!is_array($currentExtra)) {
                $currentExtra = [];
            }

            $updatedExtra = array_merge($currentExtra, [
                'ubigeo' => '150131',
                'departamento' => 'LIMA',
                'provincia' => 'LIMA',
                'distrito' => 'SAN ISIDRO',
                'urbanizacion' => 'ORRANTIA',
                'codigolocal' => '0000',
            ]);

            DB::table('core.company_settings')
                ->where('company_id', $companyId)
                ->update([
                    'address' => 'AV. PRINCIPAL 123',
                    'phone' => '+51 1 2345678',
                    'email' => 'info@empresademo.com',
                    'extra_data' => json_encode($updatedExtra),
                ]);
        } else {
            DB::table('core.company_settings')->insert([
                'company_id' => $companyId,
                'address' => 'AV. PRINCIPAL 123',
                'phone' => '+51 1 2345678',
                'email' => 'info@empresademo.com',
                'extra_data' => json_encode([
                    'ubigeo' => '150131',
                    'departamento' => 'LIMA',
                    'provincia' => 'LIMA',
                    'distrito' => 'SAN ISIDRO',
                    'urbanizacion' => 'ORRANTIA',
                    'codigolocal' => '0000',
                ]),
            ]);
        }
    }

    public function down(): void
    {
        $companyId = 1;

        DB::table('core.company_settings')
            ->where('company_id', $companyId)
            ->update([
                'address' => null,
                'phone' => null,
                'email' => null,
                'extra_data' => json_encode([]),
            ]);
    }
};
