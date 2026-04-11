<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sales\TaxBridge\SunatExceptionService;
use App\Services\Sales\TaxBridge\TaxBridgeException;
use App\Services\Sales\TaxBridge\TaxBridgeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SunatExceptionsController extends Controller
{
    public function __construct(
        private SunatExceptionService $service,
        private TaxBridgeService $taxBridgeService
    ) {
    }

    public function index(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $validator = Validator::make($request->all(), [
            'branch_id' => 'nullable|integer|min:1',
            'status' => 'nullable|string|max:40',
            'min_age_hours' => 'nullable|integer|min:0|max:720',
            'min_attempts' => 'nullable|integer|min:0|max:100',
            'only_manual_needed' => 'nullable|boolean',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:5|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

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

    public function audit(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $validator = Validator::make($request->all(), [
            'branch_id' => 'nullable|integer|min:1',
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d',
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $this->service->auditPendingVsInventory(
            $companyId,
            $request->query('branch_id') !== null ? (int) $request->query('branch_id') : null,
            $request->query('date_from'),
            $request->query('date_to'),
            (int) $request->query('limit', 200)
        );

        return response()->json($data, 200);
    }

    public function manualConfirm(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) ($request->input('company_id') ?? $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $validator = Validator::make($request->all(), [
            'resolution' => 'required|string|in:ACCEPTED,REJECTED',
            'evidence_type' => 'required|string|in:TICKET,CDR,OBSERVATION,WHATSAPP,EMAIL,OTHER',
            'evidence_ref' => 'nullable|string|max:500',
            'evidence_note' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

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
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $stats = $this->taxBridgeService->getReconcileStats($companyId);

        return response()->json($stats, 200);
    }
}
