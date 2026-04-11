<?php

namespace App\Console\Commands;

use App\Services\Sales\TaxBridge\TaxBridgeService;
use Illuminate\Console\Command;

class NotifySunatExceptionsCommand extends Command
{
    protected $signature = 'sales:notify-sunat-exceptions {--hours=6} {--limit=120}';

    protected $description = 'Envia alertas internas de excepciones SUNAT que exceden el umbral de horas.';

    public function __construct(private TaxBridgeService $taxBridgeService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $limit = max(1, min(500, (int) $this->option('limit')));

        $result = $this->taxBridgeService->notifyStaleSunatExceptions($hours, $limit);

        $this->info(sprintf(
            'Alertas SUNAT procesadas. candidatos=%d, notificados=%d, email=%d, whatsapp=%d',
            (int) ($result['candidates'] ?? 0),
            (int) ($result['notified'] ?? 0),
            (int) ($result['email'] ?? 0),
            (int) ($result['whatsapp'] ?? 0)
        ));

        return 0;
    }
}
