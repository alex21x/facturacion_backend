<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\AiOrderRequest;
use App\Services\Sales\AiOrderApplicationService;
use RuntimeException;

class AiOrderController extends Controller
{
    public function __construct(private AiOrderApplicationService $service)
    {
    }

    public function create(AiOrderRequest $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');

        try {
            $result = $this->service->execute($authUser, $companyId, $request->validated());
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => ($result['action'] ?? null) === 'SALES_REPORT'
                ? 'Reporte de ventas generado por el agente IA.'
                : 'Pedido creado por el agente IA.',
            'data' => $result,
        ], 201);
    }
}
