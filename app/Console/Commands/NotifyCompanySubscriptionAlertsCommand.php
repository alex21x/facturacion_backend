<?php

namespace App\Console\Commands;

use App\Services\AppConfig\CompanySubscriptionService;
use Illuminate\Console\Command;

class NotifyCompanySubscriptionAlertsCommand extends Command
{
    protected $signature = 'companies:notify-subscription-alerts';

    protected $description = 'Envia al admin interno el resumen de empresas con suscripciones por vencer o vencidas.';

    public function __construct(private CompanySubscriptionService $companySubscriptionService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $systemCompanyId = (int) config('app.admin_system_company_id');
        $result = $this->companySubscriptionService->notifyDueSubscriptions($systemCompanyId);

        $this->info(sprintf(
            'Alertas de suscripcion procesadas. candidatos=%d, proximas=%d, vencidas=%d, email=%d',
            (int) ($result['candidates'] ?? 0),
            (int) ($result['upcoming'] ?? 0),
            (int) ($result['overdue'] ?? 0),
            (int) ($result['email'] ?? 0)
        ));

        return 0;
    }
}