<?php

namespace App\Infrastructure\Repositories\AppConfig;

use App\Application\DTOs\AppConfig\AppConfigStationContextDTO;
use Illuminate\Support\Facades\DB;

class StationContextRepository
{
    public function tableExists(string $schema, string $table): bool
    {
        return DB::table('information_schema.tables')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->exists();
    }

    public function columnExists(string $schema, string $table, string $column): bool
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->exists();
    }

    public function findSessionDeviceId(int $sessionId): ?string
    {
        $value = DB::table('auth.refresh_tokens')
            ->where('id', $sessionId)
            ->value('device_id');

        if ($value === null) {
            return null;
        }

        return (string) $value;
    }

    public function findStationByDevice(int $companyId, string $normalizedDeviceId): ?AppConfigStationContextDTO
    {
        $row = DB::table('appcfg.pos_stations as ps')
            ->join('sales.cash_registers as cr', function ($join) use ($companyId) {
                $join->on('cr.id', '=', 'ps.cash_register_id')
                    ->where('cr.company_id', '=', $companyId)
                    ->where('cr.status', '=', 1);
            })
            ->select([
                'ps.id',
                'ps.company_id',
                'ps.cash_register_id',
                'ps.code',
                'ps.name',
                'ps.device_id',
                'ps.device_name',
                'ps.status',
                'cr.branch_id',
                'cr.warehouse_id',
                'cr.code as cash_register_code',
                'cr.name as cash_register_name',
            ])
            ->where('ps.company_id', $companyId)
            ->where('ps.status', 1)
            ->whereRaw('LOWER(TRIM(ps.device_id)) = ?', [$normalizedDeviceId])
            ->orderByDesc('ps.id')
            ->first();

        return $row ? AppConfigStationContextDTO::fromRow($row) : null;
    }
}
