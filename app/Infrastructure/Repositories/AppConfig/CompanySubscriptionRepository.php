<?php

namespace App\Infrastructure\Repositories\AppConfig;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CompanySubscriptionRepository
{
    public function getGlobalSchedule(): ?object
    {
        return DB::table('appcfg.global_subscription_schedule')
            ->where('id', 1)
            ->first();
    }

    public function upsertGlobalSchedule(array $payload, ?int $updatedBy): void
    {
        DB::table('appcfg.global_subscription_schedule')->updateOrInsert(
            ['id' => 1],
            [
                'alert_frequency' => $payload['alert_frequency'],
                'alert_time' => $payload['alert_time'],
                'weekly_digest_day' => $payload['weekly_digest_day'],
                'monthly_digest_day' => $payload['monthly_digest_day'],
                'updated_by' => $updatedBy,
                'updated_at' => now(),
            ]
        );
    }

    public function companyExists(int $companyId): bool
    {
        return DB::table('core.companies')
            ->where('id', $companyId)
            ->exists();
    }

    public function listByCompanyIds(array $companyIds): Collection
    {
        if (empty($companyIds)) {
            return collect();
        }

        return DB::table('appcfg.company_subscriptions')
            ->whereIn('company_id', $companyIds)
            ->get();
    }

    public function getByCompanyId(int $companyId): ?object
    {
        return DB::table('appcfg.company_subscriptions')
            ->where('company_id', $companyId)
            ->first();
    }

    public function upsertSubscription(int $companyId, array $payload, ?int $updatedBy): void
    {
        DB::table('appcfg.company_subscriptions')->updateOrInsert(
            ['company_id' => $companyId],
            [
                'billing_cycle' => $payload['billing_cycle'],
                'status' => $payload['status'],
                'source' => $payload['source'],
                'alerts_enabled' => $payload['alerts_enabled'],
                'enforcement_mode' => $payload['enforcement_mode'],
                'starts_at' => $payload['starts_at'],
                'current_period_starts_at' => $payload['current_period_starts_at'],
                'current_period_ends_at' => $payload['current_period_ends_at'],
                'reminder_days_before' => $payload['reminder_days_before'],
                'grace_days' => $payload['grace_days'],
                'soft_block_days_after' => $payload['soft_block_days_after'],
                'hard_block_days_after' => $payload['hard_block_days_after'],
                'admin_email_enabled' => $payload['admin_email_enabled'],
                'admin_email_recipients' => $payload['admin_email_recipients'],
                'admin_email_frequency' => $payload['admin_email_frequency'],
                'admin_email_send_hour' => $payload['admin_email_send_hour'],
                'notes' => $payload['notes'],
                'updated_by' => $updatedBy,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function listAlertCandidates(int $systemCompanyId): Collection
    {
        $adminEmailSubQuery = DB::table('auth.users as u')
            ->where('u.status', 1)
            ->whereNotNull('u.email')
            ->groupBy('u.company_id')
            ->select([
                'u.company_id',
                DB::raw('MIN(u.email) as company_admin_email'),
            ]);

        return DB::table('appcfg.company_subscriptions as s')
            ->join('core.companies as c', 'c.id', '=', 's.company_id')
            ->leftJoinSub($adminEmailSubQuery, 'au', function ($join) {
                $join->on('au.company_id', '=', 'c.id');
            })
            ->where('c.id', '!=', $systemCompanyId)
            ->where('c.status', 1)
            ->whereIn('s.billing_cycle', ['ANNUAL', 'MONTHLY'])
            ->where('s.status', 'ACTIVE')
            ->where('s.alerts_enabled', true)
            ->whereNotNull('s.current_period_ends_at')
            ->orderBy('s.current_period_ends_at')
            ->orderBy('c.legal_name')
            ->get([
                's.*',
                'c.legal_name',
                'c.trade_name',
                'c.tax_id',
                'c.email as company_email',
                'au.company_admin_email',
            ])
            ->values();
    }

    public function markPreDueAlertSent(array $companyIds): void
    {
        if (empty($companyIds)) {
            return;
        }

        DB::table('appcfg.company_subscriptions')
            ->whereIn('company_id', $companyIds)
            ->update([
                'last_pre_due_alert_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function markOverdueAlertSent(array $companyIds): void
    {
        if (empty($companyIds)) {
            return;
        }

        DB::table('appcfg.company_subscriptions')
            ->whereIn('company_id', $companyIds)
            ->update([
                'last_overdue_alert_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function markAdminDigestSent(int $companyId, string $date, string $alertState): void
    {
        DB::table('appcfg.company_subscriptions')
            ->where('company_id', $companyId)
            ->update([
                'last_admin_digest_sent_on' => $date,
                'last_admin_alert_state' => $alertState,
                'last_admin_state_sent_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function markAdminStateSent(int $companyId, string $alertState): void
    {
        DB::table('appcfg.company_subscriptions')
            ->where('company_id', $companyId)
            ->update([
                'last_admin_alert_state' => $alertState,
                'last_admin_state_sent_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function listPlatformAdminEmails(int $systemCompanyId): array
    {
        return DB::table('auth.users as u')
            ->join('auth.user_roles as ur', 'ur.user_id', '=', 'u.id')
            ->join('auth.roles as r', 'r.id', '=', 'ur.role_id')
            ->where('u.status', 1)
            ->where('u.company_id', $systemCompanyId)
            ->whereRaw("UPPER(r.code) = 'ADMIN'")
            ->whereNotNull('u.email')
            ->orderBy('u.id')
            ->pluck('u.email')
            ->map(fn ($email) => trim((string) $email))
            ->filter(fn ($email) => $email !== '')
            ->unique()
            ->values()
            ->all();
    }
}