<?php

namespace App\Services\AppConfig;

use App\Infrastructure\Repositories\AppConfig\CompanySubscriptionRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CompanySubscriptionService
{
    private const BILLING_CYCLES = ['NONE', 'ANNUAL', 'MONTHLY'];
    private const STATUSES = ['ACTIVE', 'PAUSED', 'CANCELED'];
    private const SOURCES = ['MANUAL', 'BRIDGE'];
    private const ENFORCEMENT_MODES = ['NONE', 'SOFT', 'HARD'];
    private const ADMIN_EMAIL_FREQUENCIES = ['DAILY', 'STATE_CHANGE'];

    public function __construct(private CompanySubscriptionRepository $repository)
    {
    }

    public function getGlobalSchedule(): array
    {
        $row = $this->repository->getGlobalSchedule();

        return [
            'alert_frequency' => $this->normalizeEnum($row->alert_frequency ?? 'WEEKLY', ['DAILY', 'WEEKLY', 'MONTHLY'], 'WEEKLY'),
            'alert_time' => $this->normalizeScheduleTime((string) ($row->alert_time ?? '08:00')),
            'weekly_digest_day' => max(1, min(7, (int) ($row->weekly_digest_day ?? 1))),
            'monthly_digest_day' => max(1, min(28, (int) ($row->monthly_digest_day ?? 1))),
        ];
    }

    public function updateGlobalSchedule(array $payload, ?int $updatedBy): void
    {
        $this->repository->upsertGlobalSchedule([
            'alert_frequency' => $this->normalizeEnum($payload['company_subscription_alert_frequency'] ?? 'WEEKLY', ['DAILY', 'WEEKLY', 'MONTHLY'], 'WEEKLY'),
            'alert_time' => $this->normalizeScheduleTime((string) ($payload['company_subscription_alert_time'] ?? '08:00')),
            'weekly_digest_day' => max(1, min(7, (int) ($payload['company_subscription_weekly_digest_day'] ?? 1))),
            'monthly_digest_day' => max(1, min(28, (int) ($payload['company_subscription_monthly_digest_day'] ?? 1))),
        ], $updatedBy);
    }

    public function companyExists(int $companyId): bool
    {
        return $this->repository->companyExists($companyId);
    }

    public function buildDefaultSummary(): array
    {
        $defaultHour = $this->defaultAdminSendHour();

        return [
            'billing_cycle' => 'NONE',
            'status' => 'ACTIVE',
            'source' => 'MANUAL',
            'alerts_enabled' => true,
            'enforcement_mode' => 'NONE',
            'starts_at' => null,
            'current_period_starts_at' => null,
            'current_period_ends_at' => null,
            'reminder_days_before' => 3,
            'grace_days' => 5,
            'soft_block_days_after' => 7,
            'hard_block_days_after' => 15,
            'days_until_due' => null,
            'days_overdue' => null,
            'alert_state' => 'UNCONFIGURED',
            'recommended_action' => 'Configurar ciclo y fecha de vencimiento.',
            'last_pre_due_alert_at' => null,
            'last_overdue_alert_at' => null,
            'admin_email_enabled' => false,
            'admin_email_recipients' => null,
            'admin_email_frequency' => 'DAILY',
            'admin_email_send_hour' => $defaultHour,
            'last_admin_digest_sent_on' => null,
            'last_admin_alert_state' => null,
            'last_admin_state_sent_at' => null,
            'notes' => null,
        ];
    }

    public function resolveClientAlertForCompany(int $companyId): array
    {
        if ($companyId <= 0 || !$this->companyExists($companyId)) {
            return [
                'should_show' => false,
                'tone' => 'ok',
                'title' => '',
                'detail' => '',
                'state' => 'UNCONFIGURED',
                'due_date' => null,
                'days_until_due' => null,
                'days_overdue' => null,
                'recommended_action' => null,
            ];
        }

        $row = $this->repository->getByCompanyId($companyId);
        $summary = $row ? $this->buildSummaryFromRow($row) : $this->buildDefaultSummary();
        $state = (string) ($summary['alert_state'] ?? 'UNCONFIGURED');

        if (!in_array($state, ['UPCOMING', 'DUE_TODAY', 'GRACE', 'OVERDUE', 'SOFT_BLOCK', 'HARD_BLOCK'], true)) {
            return [
                'should_show' => false,
                'tone' => 'ok',
                'title' => '',
                'detail' => '',
                'state' => $state,
                'due_date' => $summary['current_period_ends_at'],
                'days_until_due' => $summary['days_until_due'],
                'days_overdue' => $summary['days_overdue'],
                'recommended_action' => $summary['recommended_action'],
            ];
        }

        $tone = in_array($state, ['OVERDUE', 'SOFT_BLOCK', 'HARD_BLOCK'], true) ? 'bad' : 'warn';
        $title = match ($state) {
            'UPCOMING' => 'Suscripcion por vencer',
            'DUE_TODAY' => 'Suscripcion vence hoy',
            'GRACE' => 'Suscripcion en gracia',
            'OVERDUE' => 'Suscripcion vencida',
            'SOFT_BLOCK' => 'Suscripcion en escalado',
            'HARD_BLOCK' => 'Suscripcion en escalado fuerte',
            default => 'Alerta de suscripcion',
        };

        $detail = (string) ($summary['recommended_action'] ?? 'Revisa la fecha de vencimiento con administracion.');
        $dueDate = $summary['current_period_ends_at'] ?? null;
        if (is_string($dueDate) && $dueDate !== '') {
            $detail = trim($detail . ' Vencimiento: ' . $dueDate . '.');
        }

        return [
            'should_show' => true,
            'tone' => $tone,
            'title' => $title,
            'detail' => $detail,
            'state' => $state,
            'due_date' => $summary['current_period_ends_at'],
            'days_until_due' => $summary['days_until_due'],
            'days_overdue' => $summary['days_overdue'],
            'recommended_action' => $summary['recommended_action'],
        ];
    }

    public function listSummariesByCompanyIds(array $companyIds): array
    {
        if (empty($companyIds)) {
            return [];
        }

        $default = $this->buildDefaultSummary();
        $mapped = [];

        foreach ($companyIds as $companyId) {
            $mapped[(int) $companyId] = $default;
        }

        foreach ($this->repository->listByCompanyIds($companyIds) as $row) {
            $mapped[(int) $row->company_id] = $this->buildSummaryFromRow($row);
        }

        return $mapped;
    }

    public function updateCompanySubscription(int $companyId, array $payload, ?int $updatedBy): void
    {
        $billingCycle = $this->normalizeEnum($payload['billing_cycle'] ?? 'NONE', self::BILLING_CYCLES, 'NONE');
        $status = $this->normalizeEnum($payload['status'] ?? 'ACTIVE', self::STATUSES, 'ACTIVE');
        $source = $this->normalizeEnum($payload['source'] ?? 'MANUAL', self::SOURCES, 'MANUAL');
        $enforcementMode = $this->normalizeEnum($payload['enforcement_mode'] ?? 'NONE', self::ENFORCEMENT_MODES, 'NONE');

        $startsAt = $this->normalizeDate($payload['starts_at'] ?? null);
        $periodStartsAt = $this->normalizeDate($payload['current_period_starts_at'] ?? $startsAt);
        $periodEndsAt = $this->normalizeDate($payload['current_period_ends_at'] ?? null);

        if ($billingCycle === 'NONE') {
            $startsAt = null;
            $periodStartsAt = null;
            $periodEndsAt = null;
        }

        if ($periodStartsAt !== null && $periodEndsAt !== null && $periodStartsAt > $periodEndsAt) {
            throw new \InvalidArgumentException('La fecha fin de periodo no puede ser menor que la fecha inicio.');
        }

        $reminderDaysBefore = max(0, min(30, (int) ($payload['reminder_days_before'] ?? 3)));
        $graceDays = max(0, min(60, (int) ($payload['grace_days'] ?? 5)));
        $softBlockDaysAfter = max(0, min(90, (int) ($payload['soft_block_days_after'] ?? 7)));
        $hardBlockDaysAfter = max($softBlockDaysAfter, min(180, (int) ($payload['hard_block_days_after'] ?? 15)));
        $adminEmailFrequency = $this->normalizeEnum($payload['admin_email_frequency'] ?? 'DAILY', self::ADMIN_EMAIL_FREQUENCIES, 'DAILY');
        $adminEmailSendHour = max(0, min(23, (int) ($payload['admin_email_send_hour'] ?? $this->defaultAdminSendHour())));

        $this->repository->upsertSubscription($companyId, [
            'billing_cycle' => $billingCycle,
            'status' => $status,
            'source' => $source,
            'alerts_enabled' => isset($payload['alerts_enabled']) ? (bool) $payload['alerts_enabled'] : true,
            'enforcement_mode' => $enforcementMode,
            'starts_at' => $startsAt,
            'current_period_starts_at' => $periodStartsAt,
            'current_period_ends_at' => $periodEndsAt,
            'reminder_days_before' => $reminderDaysBefore,
            'grace_days' => $graceDays,
            'soft_block_days_after' => $softBlockDaysAfter,
            'hard_block_days_after' => $hardBlockDaysAfter,
            'admin_email_enabled' => isset($payload['admin_email_enabled']) ? (bool) $payload['admin_email_enabled'] : false,
            'admin_email_recipients' => $this->normalizeNullableText($payload['admin_email_recipients'] ?? null),
            'admin_email_frequency' => $adminEmailFrequency,
            'admin_email_send_hour' => $adminEmailSendHour,
            'notes' => $this->normalizeNullableText($payload['notes'] ?? null),
        ], $updatedBy);
    }

    public function notifyDueSubscriptions(int $systemCompanyId): array
    {
        $now = now('America/Lima');
        $today = $now->copy()->startOfDay();
        $upcomingDigest = [];
        $overdueDigest = [];
        $upcoming = [];
        $overdue = [];
        $preDueCompanyIds = [];
        $overdueCompanyIds = [];
        $mailQueue = [];
        $dailySent = 0;
        $stateSent = 0;
        $weeklyDigestSent = 0;

        foreach ($this->repository->listAlertCandidates($systemCompanyId) as $row) {
            $summary = $this->buildSummaryFromRow($row, $today);
            $state = $summary['alert_state'];

            if (in_array($state, ['UPCOMING', 'DUE_TODAY'], true)) {
                $upcomingDigest[] = ['row' => $row, 'summary' => $summary];
            }

            if (in_array($state, ['GRACE', 'OVERDUE', 'SOFT_BLOCK', 'HARD_BLOCK'], true)) {
                $overdueDigest[] = ['row' => $row, 'summary' => $summary];
            }

            if (in_array($state, ['UPCOMING', 'DUE_TODAY'], true) && $this->shouldSendPreDueAlert($row, $summary, $today)) {
                $upcoming[] = ['row' => $row, 'summary' => $summary];
                $preDueCompanyIds[] = (int) $row->company_id;
            }

            if (in_array($state, ['GRACE', 'OVERDUE', 'SOFT_BLOCK', 'HARD_BLOCK'], true) && $this->shouldSendOverdueAlert($row, $summary, $today)) {
                $overdue[] = ['row' => $row, 'summary' => $summary];
                $overdueCompanyIds[] = (int) $row->company_id;
            }

            if (!$this->shouldSendAdminEmailNow($row, $summary, $now)) {
                continue;
            }

            $toEmail = $this->resolveCompanyReminderEmail($row);
            if ($toEmail === null) {
                continue;
            }

            $ccEmails = $this->resolveReminderCcEmails();
            if (empty($ccEmails)) {
                Log::warning('Subscription reminder skipped: no admin CC email configured', [
                    'company_id' => (int) ($row->company_id ?? 0),
                ]);
                continue;
            }

            $mailQueue[] = [
                'row' => $row,
                'summary' => $summary,
                'to' => [$toEmail],
                'cc' => $ccEmails,
            ];
        }

        if (empty($upcomingDigest) && empty($overdueDigest) && empty($mailQueue)) {
            return ['candidates' => 0, 'upcoming' => 0, 'overdue' => 0, 'email' => 0];
        }

        foreach ($mailQueue as $mailItem) {
            $row = $mailItem['row'];
            $summary = $mailItem['summary'];
            $companyName = trim((string) ($row->trade_name ?? $row->legal_name ?? 'Empresa'));

            $subject = sprintf(
                'Recordatorio de suscripcion - %s (%s)',
                $companyName,
                (string) ($summary['alert_state'] ?? 'ALERTA')
            );
            $html = $this->buildAlertEmailHtml([
                ['row' => $row, 'summary' => $summary],
            ], [], $today);

            if (in_array((string) ($summary['alert_state'] ?? ''), ['GRACE', 'OVERDUE', 'SOFT_BLOCK', 'HARD_BLOCK'], true)) {
                $html = $this->buildAlertEmailHtml([], [
                    ['row' => $row, 'summary' => $summary],
                ], $today);
            }

            $fromAddress = $this->resolveMailFromAddress();
            if ($fromAddress === null) {
                Log::warning('Subscription reminder skipped: no valid mail.from.address configured');
                continue;
            }

            $fromName = (string) config('mail.from.name', config('app.name', 'Facturacion'));
            Mail::html($html, function ($mail) use ($mailItem, $subject, $fromAddress, $fromName) {
                $mail->to($mailItem['to'])
                    ->cc($mailItem['cc'])
                    ->from($fromAddress, $fromName)
                    ->subject($subject);
            });

            $companyId = (int) ($row->company_id ?? 0);
            $frequency = $this->normalizeEnum(($row->admin_email_frequency ?? 'DAILY'), self::ADMIN_EMAIL_FREQUENCIES, 'DAILY');
            $state = (string) ($summary['alert_state'] ?? 'UNCONFIGURED');
            if ($companyId > 0 && $frequency === 'DAILY') {
                $this->repository->markAdminDigestSent($companyId, $today->toDateString(), $state);
                $dailySent++;
            } elseif ($companyId > 0) {
                $this->repository->markAdminStateSent($companyId, $state);
                $stateSent++;
            }
        }

        if (!empty($upcomingDigest) || !empty($overdueDigest)) {
            $digestRecipients = $this->resolveWeeklyDigestEmails();
            if (!empty($digestRecipients)) {
                $subject = sprintf(
                    'Resumen semanal de suscripciones: %d proximas, %d vencidas',
                    count($upcomingDigest),
                    count($overdueDigest)
                );
                $html = $this->buildAlertEmailHtml($upcomingDigest, $overdueDigest, $today);

                $fromAddress = $this->resolveMailFromAddress();
                if ($fromAddress === null) {
                    Log::warning('Subscription weekly digest skipped: no valid mail.from.address configured');
                } else {
                    $fromName = (string) config('mail.from.name', config('app.name', 'Facturacion'));
                    Mail::html($html, function ($mail) use ($digestRecipients, $subject, $fromAddress, $fromName) {
                        $mail->to($digestRecipients)
                            ->from($fromAddress, $fromName)
                            ->subject($subject);
                    });

                    $weeklyDigestSent = 1;
                }
            }
        }

        $this->repository->markPreDueAlertSent(array_values(array_unique($preDueCompanyIds)));
        $this->repository->markOverdueAlertSent(array_values(array_unique($overdueCompanyIds)));

        return [
            'candidates' => count($upcomingDigest) + count($overdueDigest),
            'upcoming' => count($upcomingDigest),
            'overdue' => count($overdueDigest),
            'email' => count($mailQueue) + $weeklyDigestSent,
            'daily_sent' => $dailySent,
            'state_sent' => $stateSent,
            'weekly_digest_sent' => $weeklyDigestSent,
        ];
    }

    private function buildSummaryFromRow(object $row, ?Carbon $today = null): array
    {
        $today = $today ?: now()->startOfDay();
        $summary = $this->buildDefaultSummary();

        $summary['billing_cycle'] = $this->normalizeEnum($row->billing_cycle ?? 'NONE', self::BILLING_CYCLES, 'NONE');
        $summary['status'] = $this->normalizeEnum($row->status ?? 'ACTIVE', self::STATUSES, 'ACTIVE');
        $summary['source'] = $this->normalizeEnum($row->source ?? 'MANUAL', self::SOURCES, 'MANUAL');
        $summary['alerts_enabled'] = (bool) ($row->alerts_enabled ?? true);
        $summary['enforcement_mode'] = $this->normalizeEnum($row->enforcement_mode ?? 'NONE', self::ENFORCEMENT_MODES, 'NONE');
        $summary['starts_at'] = $this->normalizeDate($row->starts_at ?? null);
        $summary['current_period_starts_at'] = $this->normalizeDate($row->current_period_starts_at ?? null);
        $summary['current_period_ends_at'] = $this->normalizeDate($row->current_period_ends_at ?? null);
        $summary['reminder_days_before'] = max(0, (int) ($row->reminder_days_before ?? 3));
        $summary['grace_days'] = max(0, (int) ($row->grace_days ?? 5));
        $summary['soft_block_days_after'] = max(0, (int) ($row->soft_block_days_after ?? 7));
        $summary['hard_block_days_after'] = max($summary['soft_block_days_after'], (int) ($row->hard_block_days_after ?? 15));
        $summary['last_pre_due_alert_at'] = isset($row->last_pre_due_alert_at) && $row->last_pre_due_alert_at !== null ? (string) $row->last_pre_due_alert_at : null;
        $summary['last_overdue_alert_at'] = isset($row->last_overdue_alert_at) && $row->last_overdue_alert_at !== null ? (string) $row->last_overdue_alert_at : null;
        $summary['admin_email_enabled'] = isset($row->admin_email_enabled) ? (bool) $row->admin_email_enabled : false;
        $summary['admin_email_recipients'] = $this->normalizeNullableText($row->admin_email_recipients ?? null);
        $summary['admin_email_frequency'] = $this->normalizeEnum($row->admin_email_frequency ?? 'DAILY', self::ADMIN_EMAIL_FREQUENCIES, 'DAILY');
        $summary['admin_email_send_hour'] = max(0, min(23, (int) ($row->admin_email_send_hour ?? $this->defaultAdminSendHour())));
        $summary['last_admin_digest_sent_on'] = isset($row->last_admin_digest_sent_on) && $row->last_admin_digest_sent_on !== null ? (string) $row->last_admin_digest_sent_on : null;
        $summary['last_admin_alert_state'] = isset($row->last_admin_alert_state) && $row->last_admin_alert_state !== null ? (string) $row->last_admin_alert_state : null;
        $summary['last_admin_state_sent_at'] = isset($row->last_admin_state_sent_at) && $row->last_admin_state_sent_at !== null ? (string) $row->last_admin_state_sent_at : null;
        $summary['notes'] = $this->normalizeNullableText($row->notes ?? null);

        if ($summary['billing_cycle'] === 'NONE' || $summary['current_period_ends_at'] === null) {
            return $summary;
        }

        if ($summary['status'] === 'PAUSED') {
            $summary['alert_state'] = 'PAUSED';
            $summary['recommended_action'] = 'Seguimiento pausado manualmente.';
            return $summary;
        }

        if ($summary['status'] === 'CANCELED') {
            $summary['alert_state'] = 'CANCELED';
            $summary['recommended_action'] = 'Suscripción cancelada; no enviar alertas.';
            return $summary;
        }

        $dueDate = Carbon::parse($summary['current_period_ends_at'])->startOfDay();
        $daysUntilDue = $today->diffInDays($dueDate, false);
        $summary['days_until_due'] = $daysUntilDue >= 0 ? $daysUntilDue : null;
        $summary['days_overdue'] = $daysUntilDue < 0 ? abs($daysUntilDue) : null;

        if ($daysUntilDue > $summary['reminder_days_before']) {
            $summary['alert_state'] = 'OK';
            $summary['recommended_action'] = 'Sin alertas por ahora.';
            return $summary;
        }

        if ($daysUntilDue > 0) {
            $summary['alert_state'] = 'UPCOMING';
            $summary['recommended_action'] = sprintf('Recordar pago faltando %d dia(s).', $daysUntilDue);
            return $summary;
        }

        if ($daysUntilDue === 0) {
            $summary['alert_state'] = 'DUE_TODAY';
            $summary['recommended_action'] = 'Enviar recordatorio amable hoy; sin bloqueo.';
            return $summary;
        }

        $daysOverdue = abs($daysUntilDue);
        if ($daysOverdue <= $summary['grace_days']) {
            $summary['alert_state'] = 'GRACE';
            $summary['recommended_action'] = sprintf('En gracia: %d de %d dia(s).', $daysOverdue, $summary['grace_days']);
            return $summary;
        }

        if ($summary['enforcement_mode'] !== 'NONE' && $summary['hard_block_days_after'] > 0 && $daysOverdue >= $summary['hard_block_days_after']) {
            $summary['alert_state'] = 'HARD_BLOCK';
            $summary['recommended_action'] = 'Escalar a revisión de bloqueo completo.';
            return $summary;
        }

        if ($summary['enforcement_mode'] !== 'NONE' && $summary['soft_block_days_after'] > 0 && $daysOverdue >= $summary['soft_block_days_after']) {
            $summary['alert_state'] = 'SOFT_BLOCK';
            $summary['recommended_action'] = 'Evaluar restricción parcial sin cortar el servicio por completo.';
            return $summary;
        }

        $summary['alert_state'] = 'OVERDUE';
        $summary['recommended_action'] = 'Gestionar cobro manual antes de restringir.';

        return $summary;
    }

    private function shouldSendPreDueAlert(object $row, array $summary, Carbon $today): bool
    {
        if (!$summary['alerts_enabled']) {
            return false;
        }

        $lastAlert = $this->parseDateTime($row->last_pre_due_alert_at ?? null);
        if ($lastAlert === null) {
            return true;
        }

        $threshold = Carbon::parse($summary['current_period_ends_at'])->startOfDay()->subDays((int) $summary['reminder_days_before']);
        return $lastAlert->lt($threshold);
    }

    private function shouldSendOverdueAlert(object $row, array $summary, Carbon $today): bool
    {
        if (!$summary['alerts_enabled']) {
            return false;
        }

        $lastAlert = $this->parseDateTime($row->last_overdue_alert_at ?? null);
        if ($lastAlert === null) {
            return true;
        }

        $threshold = Carbon::parse($summary['current_period_ends_at'])->startOfDay()->addDay();
        return $lastAlert->lt($threshold);
    }

    private function resolveCompanyReminderEmail(object $row): ?string
    {
        $companyEmail = trim((string) ($row->company_email ?? ''));
        if (filter_var($companyEmail, FILTER_VALIDATE_EMAIL)) {
            return $companyEmail;
        }

        $fallbackAdminUserEmail = trim((string) ($row->company_admin_email ?? ''));
        if (filter_var($fallbackAdminUserEmail, FILTER_VALIDATE_EMAIL)) {
            return $fallbackAdminUserEmail;
        }

        return null;
    }

    private function resolveReminderCcEmails(): array
    {
        $configured = trim((string) config('app.company_subscription_admin_cc_email', ''));
        if ($configured === '') {
            return [];
        }

        return collect(preg_split('/[,;\s]+/', $configured) ?: [])
            ->map(fn ($email) => trim((string) $email))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }

    private function resolveWeeklyDigestEmails(): array
    {
        $configured = trim((string) config('app.company_subscription_weekly_digest_emails', ''));
        if ($configured !== '') {
            return collect(preg_split('/[,;\s]+/', $configured) ?: [])
                ->map(fn ($email) => trim((string) $email))
                ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
                ->unique()
                ->values()
                ->all();
        }

        return $this->resolveReminderCcEmails();
    }

    private function shouldSendAdminEmailNow(object $row, array $summary, Carbon $now): bool
    {
        if (!in_array((string) ($summary['alert_state'] ?? ''), ['UPCOMING', 'DUE_TODAY', 'GRACE', 'OVERDUE', 'SOFT_BLOCK', 'HARD_BLOCK'], true)) {
            return false;
        }

        if (!isset($row->admin_email_enabled) || !(bool) $row->admin_email_enabled) {
            return false;
        }

        $frequency = $this->normalizeEnum($row->admin_email_frequency ?? 'DAILY', self::ADMIN_EMAIL_FREQUENCIES, 'DAILY');
        if ($frequency === 'DAILY') {
            $sendHour = max(0, min(23, (int) ($row->admin_email_send_hour ?? $this->defaultAdminSendHour())));
            if ((int) $now->hour < $sendHour) {
                return false;
            }

            $lastDigestDate = trim((string) ($row->last_admin_digest_sent_on ?? ''));
            return $lastDigestDate !== $now->toDateString();
        }

        $currentState = strtoupper(trim((string) ($summary['alert_state'] ?? 'UNCONFIGURED')));
        $lastState = strtoupper(trim((string) ($row->last_admin_alert_state ?? '')));
        return $currentState !== $lastState;
    }

    private function defaultAdminSendHour(): int
    {
        $time = trim((string) config('app.company_subscription_alert_time', '08:00'));
        if (preg_match('/^(\d{1,2}):\d{2}$/', $time, $matches) !== 1) {
            return 8;
        }

        return max(0, min(23, (int) ($matches[1] ?? 8)));
    }

    private function buildAlertEmailHtml(array $upcoming, array $overdue, Carbon $today): string
    {
        $html = '<h2>Resumen de suscripciones</h2>';
        $html .= '<p>Fecha de control: ' . htmlspecialchars($today->format('Y-m-d'), ENT_QUOTES, 'UTF-8') . '</p>';

        if (!empty($upcoming)) {
            $html .= '<h3>Proximas a vencer</h3><ul>';
            foreach ($upcoming as $item) {
                $row = $item['row'];
                $summary = $item['summary'];
                $html .= '<li>'
                    . htmlspecialchars((string) $row->legal_name, ENT_QUOTES, 'UTF-8')
                    . ' (' . htmlspecialchars((string) ($row->tax_id ?? 'sin RUC'), ENT_QUOTES, 'UTF-8') . ')'
                    . ' - vence ' . htmlspecialchars((string) $summary['current_period_ends_at'], ENT_QUOTES, 'UTF-8')
                    . ' - ' . htmlspecialchars((string) $summary['recommended_action'], ENT_QUOTES, 'UTF-8')
                    . '</li>';
            }
            $html .= '</ul>';
        }

        if (!empty($overdue)) {
            $html .= '<h3>Vencidas o en seguimiento</h3><ul>';
            foreach ($overdue as $item) {
                $row = $item['row'];
                $summary = $item['summary'];
                $html .= '<li>'
                    . htmlspecialchars((string) $row->legal_name, ENT_QUOTES, 'UTF-8')
                    . ' (' . htmlspecialchars((string) ($row->tax_id ?? 'sin RUC'), ENT_QUOTES, 'UTF-8') . ')'
                    . ' - vencio ' . htmlspecialchars((string) $summary['current_period_ends_at'], ENT_QUOTES, 'UTF-8')
                    . ' - estado ' . htmlspecialchars((string) $summary['alert_state'], ENT_QUOTES, 'UTF-8')
                    . ' - ' . htmlspecialchars((string) $summary['recommended_action'], ENT_QUOTES, 'UTF-8')
                    . '</li>';
            }
            $html .= '</ul>';
        }

        $html .= '<p>Recomendacion: usar primero recordatorios y dias de gracia; solo escalar a restriccion cuando la mora persista.</p>';

        return $html;
    }

    private function normalizeEnum(mixed $value, array $allowed, string $fallback): string
    {
        $normalized = strtoupper(trim((string) $value));
        return in_array($normalized, $allowed, true) ? $normalized : $fallback;
    }

    private function normalizeDate(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }

        try {
            return Carbon::parse($text)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function normalizeNullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : $text;
    }

    private function parseDateTime(mixed $value): ?Carbon
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }

        try {
            return Carbon::parse($text);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resolveMailFromAddress(): ?string
    {
        $configured = trim((string) config('mail.from.address', ''));
        return filter_var($configured, FILTER_VALIDATE_EMAIL) ? $configured : null;
    }

    private function normalizeScheduleTime(string $time): string
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $matches) !== 1) {
            return '08:00';
        }

        return sprintf('%02d:%02d', max(0, min(23, (int) $matches[1])), max(0, min(59, (int) $matches[2])));
    }
}