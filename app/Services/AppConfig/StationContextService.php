<?php

namespace App\Services\AppConfig;

use App\Infrastructure\Repositories\AppConfig\StationContextRepository;

class StationContextService
{
    public function __construct(
        private StationContextRepository $stationContextRepository
    ) {
    }

    public function resolve(int $sessionId, int $companyId): ?array
    {
        if ($sessionId <= 0) {
            return null;
        }

        if (!$this->stationContextRepository->tableExists('appcfg', 'pos_stations')
            || !$this->stationContextRepository->columnExists('auth', 'refresh_tokens', 'device_id')) {
            return null;
        }

        $sessionDeviceId = $this->stationContextRepository->findSessionDeviceId($sessionId);
        $deviceId = trim((string) ($sessionDeviceId ?? ''));
        if ($deviceId === '') {
            return null;
        }

        $station = $this->stationContextRepository->findStationByDevice($companyId, mb_strtolower(trim($deviceId)));
        if (!$station) {
            return null;
        }

        return [
            'id' => (int) $station->id,
            'company_id' => (int) $station->company_id,
            'cash_register_id' => (int) $station->cash_register_id,
            'branch_id' => $station->branch_id !== null ? (int) $station->branch_id : null,
            'warehouse_id' => $station->warehouse_id !== null ? (int) $station->warehouse_id : null,
            'code' => (string) $station->code,
            'name' => (string) $station->name,
            'device_id' => (string) $station->device_id,
            'device_name' => $station->device_name !== null ? (string) $station->device_name : null,
            'status' => (int) $station->status,
            'cash_register_code' => (string) $station->cash_register_code,
            'cash_register_name' => (string) $station->cash_register_name,
        ];
    }
}
