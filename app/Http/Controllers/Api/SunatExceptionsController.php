<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SunatExceptions\AuditSunatExceptionsRequest;
use App\Http\Requests\SunatExceptions\IndexSunatExceptionsRequest;
use App\Http\Requests\SunatExceptions\ManualConfirmSunatExceptionRequest;
use App\Services\Sales\TaxBridge\SunatExceptionService;
use App\Services\Sales\TaxBridge\TaxBridgeException;
use App\Services\Sales\TaxBridge\TaxBridgeService;
use Illuminate\Http\Request;

class SunatExceptionsController extends Controller
{
    public function __construct(
        private SunatExceptionService $service,
        private TaxBridgeService $taxBridgeService
    ) {
    }

    public function index(IndexSunatExceptionsRequest $request)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        $data = $this->service->list(
            $companyId,
            $request->query('branch_id') !== null ? (int) $request->query('branch_id') : null,
            $request->query('status'),
            (int) $request->query('min_age_hours', 0),
            (int) $request->query('min_attempts', 0),
            filter_var($request->query('only_manual_needed', false), FILTER_VALIDATE_BOOLEAN),
            (int) $request->query('page', 1),
            (int) $request->query('per_page', 20)
        );

        return response()->json($data, 200);
    }

    public function audit(AuditSunatExceptionsRequest $request)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        $data = $this->service->auditPendingVsInventory(
            $companyId,
            $request->query('branch_id') !== null ? (int) $request->query('branch_id') : null,
            $request->query('date_from'),
            $request->query('date_to'),
            (int) $request->query('limit', 200)
        );

        return response()->json($data, 200);
    }

    public function manualConfirm(ManualConfirmSunatExceptionRequest $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');

        try {
            $data = $this->service->manualConfirm(
                $companyId,
                $id,
                (int) $authUser->id,
                strtoupper((string) $request->input('resolution')),
                strtoupper((string) $request->input('evidence_type')),
                $request->input('evidence_ref'),
                $request->input('evidence_note')
            );

            return response()->json($data, 200);
        } catch (TaxBridgeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() > 0 ? $e->getCode() : 422);
        }
    }

    public function reconcileStats(Request $request)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        $stats = $this->taxBridgeService->getReconcileStats($companyId);

        return response()->json($stats, 200);
    }
}
