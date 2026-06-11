<?php

namespace App\Services\AppConfig;

use App\Infrastructure\Repositories\AppConfig\StationContextRepository;
use Illuminate\Support\Facades\Cache;

class StationContextService
{
    private const SCHEMA_CACHE_TTL_SECONDS = 300;
    private const STATION_RESOLVE_CACHE_TTL_SECONDS = 60;

    private ?bool $hasStationContextSchema = null;

    public function __construct(
        private StationContextRepository $stationContextRepository
    ) {
    }

    public function resolve(int $sessionId, int $companyId): ?array
    {
        if ($sessionId <= 0) {
            return null;
        }

        if (!$this->hasRequiredSchema()) {
            return null;
        }

        $cachePayload = Cache::remember(
            'station_context:resolve:v1:session:' . $sessionId . ':company:' . $companyId,
            self::STATION_RESOLVE_CACHE_TTL_SECONDS,
            function () use ($sessionId, $companyId) {
                $sessionDeviceId = $this->stationContextRepository->findSessionDeviceId($sessionId);
                $deviceId = trim((string) ($sessionDeviceId ?? ''));
                if ($deviceId === '') {
                    return ['resolved' => false];
                }

                $station = $this->stationContextRepository->findStationByDevice($companyId, mb_strtolower(trim($deviceId)));
                if (!$station) {
                    return ['resolved' => false];
                }

                return [
                    'resolved' => true,
                    'station' => [
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
                    ],
                ];
            }
        );

        if (!is_array($cachePayload) || !($cachePayload['resolved'] ?? false)) {
            return null;
        }

        return (array) ($cachePayload['station'] ?? []);
    }

    private function hasRequiredSchema(): bool
    {
        if ($this->hasStationContextSchema !== null) {
            return $this->hasStationContextSchema;
        }

        $this->hasStationContextSchema = Cache::remember(
            'station_context:schema_ready:v1',
            self::SCHEMA_CACHE_TTL_SECONDS,
            function () {
                return $this->stationContextRepository->tableExists('appcfg', 'pos_stations')
                    && $this->stationContextRepository->columnExists('auth', 'refresh_tokens', 'device_id');
            }
        );

        return $this->hasStationContextSchema;
    }
}
