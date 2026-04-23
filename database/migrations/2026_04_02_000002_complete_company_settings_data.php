<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('core.company_settings')) {
            return;
        }

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

            $payload = [];

            if (Schema::hasColumn('core.company_settings', 'address')) {
                $payload['address'] = 'AV. PRINCIPAL 123';
            }

            if (Schema::hasColumn('core.company_settings', 'phone')) {
                $payload['phone'] = '+51 1 2345678';
            }

            if (Schema::hasColumn('core.company_settings', 'email')) {
                $payload['email'] = 'info@empresademo.com';
            }

            if (Schema::hasColumn('core.company_settings', 'extra_data')) {
                $payload['extra_data'] = json_encode($updatedExtra);
            }

            if (!empty($payload)) {
                DB::table('core.company_settings')
                    ->where('company_id', $companyId)
                    ->update($payload);
            }
        } else {
            $payload = [];

            if (Schema::hasColumn('core.company_settings', 'company_id')) {
                $payload['company_id'] = $companyId;
            }

            if (Schema::hasColumn('core.company_settings', 'address')) {
                $payload['address'] = 'AV. PRINCIPAL 123';
            }

            if (Schema::hasColumn('core.company_settings', 'phone')) {
                $payload['phone'] = '+51 1 2345678';
            }

            if (Schema::hasColumn('core.company_settings', 'email')) {
                $payload['email'] = 'info@empresademo.com';
            }

            if (Schema::hasColumn('core.company_settings', 'extra_data')) {
                $payload['extra_data'] = json_encode([
                    'ubigeo' => '150131',
                    'departamento' => 'LIMA',
                    'provincia' => 'LIMA',
                    'distrito' => 'SAN ISIDRO',
                    'urbanizacion' => 'ORRANTIA',
                    'codigolocal' => '0000',
                ]);
            }

            if (!empty($payload)) {
                DB::table('core.company_settings')->insert($payload);
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('core.company_settings')) {
            return;
        }

        $companyId = 1;

        $payload = [];

        if (Schema::hasColumn('core.company_settings', 'address')) {
            $payload['address'] = null;
        }

        if (Schema::hasColumn('core.company_settings', 'phone')) {
            $payload['phone'] = null;
        }

        if (Schema::hasColumn('core.company_settings', 'email')) {
            $payload['email'] = null;
        }

        if (Schema::hasColumn('core.company_settings', 'extra_data')) {
            $payload['extra_data'] = json_encode([]);
        }

        if (empty($payload) || !Schema::hasColumn('core.company_settings', 'company_id')) {
            return;
        }

        DB::table('core.company_settings')
            ->where('company_id', $companyId)
            ->update($payload);
    }
};
