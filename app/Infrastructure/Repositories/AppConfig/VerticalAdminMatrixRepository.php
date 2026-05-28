<?php

namespace App\Infrastructure\Repositories\AppConfig;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VerticalAdminMatrixRepository
{
    public function tableExists(string $schema, string $table): bool
    {
        return DB::table('information_schema.tables')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->exists();
    }

    public function listActiveVerticals(): Collection
    {
        return DB::table('appcfg.verticals')
            ->where('status', 1)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'description']);
    }

    public function listCompanyVerticalSettingsRows(int $companyId): Collection
    {
        return DB::table('appcfg.verticals as v')
            ->leftJoin('appcfg.company_verticals as cv', function ($join) use ($companyId) {
                $join->on('cv.vertical_id', '=', 'v.id')
                    ->where('cv.company_id', '=', $companyId)
                    ->where('cv.status', '=', 1);
            })
            ->select([
                'v.id',
                'v.code',
                'v.name',
                'v.description',
                'v.status',
                DB::raw('CASE WHEN cv.id IS NULL THEN false ELSE true END as is_assigned'),
                DB::raw('CASE WHEN cv.is_primary IS NULL THEN false ELSE cv.is_primary END as is_primary'),
                'cv.effective_from',
                'cv.effective_to',
            ])
            ->where('v.status', 1)
            ->orderBy('v.name')
            ->get();
    }

    public function listNonSystemCompanies(int $systemCompanyId): Collection
    {
        return DB::table('core.companies')
            ->where('id', '!=', $systemCompanyId)
            ->orderBy('legal_name')
            ->get(['id', 'tax_id', 'legal_name', 'trade_name', 'status']);
    }

    public function listAssignmentsByCompanyIds(array $companyIds): Collection
    {
        return DB::table('appcfg.company_verticals as cv')
            ->join('appcfg.verticals as v', 'v.id', '=', 'cv.vertical_id')
            ->where('v.status', 1)
            ->whereIn('cv.company_id', $companyIds)
            ->orderBy('cv.company_id')
            ->orderBy('v.name')
            ->get([
                'cv.company_id',
                'cv.vertical_id',
                'v.code as vertical_code',
                'v.name as vertical_name',
                'cv.status',
                'cv.is_primary',
                'cv.effective_from',
                'cv.effective_to',
            ]);
    }

    public function listAdminUsersByCompanyIds(array $companyIds): Collection
    {
        return DB::table('auth.users as u')
            ->join('auth.user_roles as ur', 'ur.user_id', '=', 'u.id')
            ->join('auth.roles as r', 'r.id', '=', 'ur.role_id')
            ->where('u.status', 1)
            ->whereRaw("UPPER(r.code) = 'ADMIN'")
            ->whereIn('u.company_id', $companyIds)
            ->orderBy('u.id')
            ->get(['u.id', 'u.company_id', 'u.username', 'u.email']);
    }

    public function listFallbackUsersByCompanyIds(array $companyIds): Collection
    {
        return DB::table('auth.users as u')
            ->where('u.status', 1)
            ->whereIn('u.company_id', $companyIds)
            ->orderBy('u.company_id')
            ->orderBy('u.id')
            ->get(['u.id', 'u.company_id', 'u.username', 'u.email']);
    }

    public function existingCompanyIds(array $companyIds): array
    {
        return DB::table('core.companies')
            ->whereIn('id', $companyIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function findActiveVerticalByCode(string $verticalCode): ?object
    {
        $row = DB::table('appcfg.verticals')
            ->whereRaw('UPPER(code) = ?', [$verticalCode])
            ->where('status', 1)
            ->first(['id', 'code', 'name']);

        return $row ? (object) $row : null;
    }

    public function runInTransaction(callable $callback): void
    {
        DB::transaction($callback);
    }

    public function updateCompanyStatus(int $companyId, int $status): void
    {
        DB::table('core.companies')
            ->where('id', $companyId)
            ->update([
                'status' => $status,
                'updated_at' => now(),
            ]);
    }

    public function clearEnabledPrimaryVerticals(int $companyId, int $updatedBy): void
    {
        DB::table('appcfg.company_verticals')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->update([
                'is_primary' => false,
                'updated_by' => $updatedBy,
                'updated_at' => now(),
            ]);
    }

    public function upsertCompanyVertical(
        int $companyId,
        int $verticalId,
        bool $isEnabled,
        bool $isPrimary,
        string $effectiveFrom,
        int $updatedBy
    ): void {
        DB::table('appcfg.company_verticals')->updateOrInsert(
            [
                'company_id' => $companyId,
                'vertical_id' => $verticalId,
            ],
            [
                'status' => $isEnabled ? 1 : 0,
                'is_primary' => $isPrimary,
                'effective_from' => $effectiveFrom,
                'effective_to' => $isEnabled ? null : now()->toDateString(),
                'updated_by' => $updatedBy,
                'updated_at' => now(),
                'created_by' => $updatedBy,
                'created_at' => now(),
            ]
        );
    }

    public function disableEnabledCompanyVerticals(int $companyId, int $updatedBy): void
    {
        DB::table('appcfg.company_verticals')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->update([
                'status' => 0,
                'is_primary' => false,
                'effective_to' => now()->toDateString(),
                'updated_by' => $updatedBy,
                'updated_at' => now(),
            ]);
    }

    public function listCompanyUserIds(int $companyId): array
    {
        return DB::table('auth.users')
            ->where('company_id', $companyId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function revokeActiveRefreshTokensByUserIds(array $userIds): void
    {
        if (empty($userIds)) {
            return;
        }

        DB::table('auth.refresh_tokens')
            ->whereIn('user_id', $userIds)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
            ]);
    }

    public function hasEnabledPrimaryVertical(int $companyId): bool
    {
        return DB::table('appcfg.company_verticals')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->where('is_primary', true)
            ->exists();
    }

    public function findLatestEnabledVerticalId(int $companyId): ?int
    {
        $row = DB::table('appcfg.company_verticals')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->orderBy('updated_at', 'desc')
            ->first(['id']);

        return $row ? (int) $row->id : null;
    }

    public function markVerticalAsPrimary(int $companyVerticalId, int $updatedBy): void
    {
        DB::table('appcfg.company_verticals')
            ->where('id', $companyVerticalId)
            ->update([
                'is_primary' => true,
                'updated_by' => $updatedBy,
                'updated_at' => now(),
            ]);
    }
}
