<?php

namespace App\Infrastructure\Repositories\AppConfig;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CompanyRateLimitRepository
{
    public function tableExists(string $schema, string $table): bool
    {
        return DB::table('information_schema.tables')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->exists();
    }

    public function listNonSystemCompanies(int $systemCompanyId): Collection
    {
        return DB::table('core.companies')
            ->where('id', '!=', $systemCompanyId)
            ->orderBy('legal_name')
            ->get(['id', 'tax_id', 'legal_name', 'trade_name', 'status']);
    }

    public function getAllCompanyRateLimits(): Collection
    {
        return DB::table('appcfg.company_rate_limits')
            ->get([
                'company_id',
                'is_enabled',
                'requests_per_minute',
                'requests_per_minute_read',
                'requests_per_minute_write',
                'requests_per_minute_reports',
                'plan_code',
                'last_preset_code',
                'updated_at',
            ]);
    }

    public function companyExists(int $companyId): bool
    {
        return DB::table('core.companies')->where('id', $companyId)->exists();
    }

    public function existingCompanyIds(array $companyIds): array
    {
        return DB::table('core.companies')
            ->whereIn('id', $companyIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function upsertCompanyRateLimit(int $companyId, array $payload, ?int $updatedBy): void
    {
        DB::table('appcfg.company_rate_limits')->updateOrInsert(
            ['company_id' => $companyId],
            [
                'is_enabled' => (bool) $payload['is_enabled'],
                'requests_per_minute' => (int) $payload['requests_per_minute_read'],
                'requests_per_minute_read' => (int) $payload['requests_per_minute_read'],
                'requests_per_minute_write' => (int) $payload['requests_per_minute_write'],
                'requests_per_minute_reports' => (int) $payload['requests_per_minute_reports'],
                'plan_code' => (string) ($payload['plan_code'] ?? 'CUSTOM'),
                'last_preset_code' => $payload['preset_code'] ?? null,
                'updated_by' => $updatedBy,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }
}
