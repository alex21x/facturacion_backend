<?php

namespace App\Services\Sales\TaxBridge;

use App\Contracts\TaxBridgeGateway;
use App\Infrastructure\Repositories\Sales\TaxBridge\TaxBridgeService as TaxBridgeRepositoryService;

class TaxBridgeService extends TaxBridgeRepositoryService implements TaxBridgeGateway
{
}
