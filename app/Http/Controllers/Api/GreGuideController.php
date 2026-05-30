<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GreGuide\CancelGreGuideRequest;
use App\Http\Requests\GreGuide\IndexGreGuideRequest;
use App\Http\Requests\GreGuide\PrefillGreGuideFromDocumentRequest;
use App\Http\Requests\GreGuide\SearchUbigeosRequest;
use App\Http\Requests\GreGuide\StoreGreGuideRequest;
use App\Http\Requests\GreGuide\UpdateGreGuideRequest;
use App\Services\Sales\TaxBridge\GreGuideService;
use App\Services\Sales\TaxBridge\TaxBridgeAuditService;
use App\Services\Sales\TaxBridge\TaxBridgeException;
use Illuminate\Http\Request;

class GreGuideController extends Controller
{
    public function __construct(
        private GreGuideService $service,
        private TaxBridgeAuditService $auditService
    )
    {
    }

    public function index(IndexGreGuideRequest $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $request->validated();

        $data = $this->service->list(
            $companyId,
            [
                'status' => $request->query('status'),
                'issue_date' => $request->query('issue_date'),
                'search' => $request->query('search'),
            ],
            (int) $request->query('page', 1),
            (int) $request->query('per_page', 20)
        );

        return response()->json($data, 200);
    }

    public function lookups(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $branchId = $request->query('branch_id', $authUser->branch_id);

        if ($branchId !== null && $branchId !== '') {
            $branchId = (int) $branchId;
        } else {
            $branchId = null;
        }

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        if ($branchId !== null) {
            $branchExists = $this->service->branchExistsForCompany($companyId, $branchId);

            if (!$branchExists) {
                return response()->json(['message' => 'Invalid branch scope'], 422);
            }
        }

        return response()->json($this->service->lookups($companyId, $branchId), 200);
    }

    public function ubigeos(SearchUbigeosRequest $request)
    {
        $request->validated();

        $q = (string) $request->query('q');
        $limit = (int) $request->query('limit', 30);

        return response()->json([
            'data' => $this->service->searchUbigeos($q, $limit),
        ], 200);
    }

    public function prefillFromDocument(PrefillGreGuideFromDocumentRequest $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $request->validated();

        try {
            $documentId = (int) $request->query('document_id', 0);
            if ($documentId > 0) {
                return response()->json(
                    $this->service->prefillFromCommercialDocument($companyId, $documentId),
                    200
                );
            }

            $series = trim((string) $request->query('series', ''));
            $number = (int) $request->query('number', 0);
            if ($series === '' || $number <= 0) {
                return response()->json([
                    'message' => 'Debes enviar document_id o serie y numero de comprobante',
                ], 422);
            }

            return response()->json(
                $this->service->prefillFromCommercialDocumentRef(
                    $companyId,
                    $series,
                    $number,
                    $request->query('document_kind')
                ),
                200
            );
        } catch (TaxBridgeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus());
        }
    }

    public function show(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $guide = $this->service->show($companyId, $id);
        if (!$guide) {
            return response()->json(['message' => 'Guia GRE no encontrada'], 404);
        }

        return response()->json($guide, 200);
    }

    public function taxBridgeAuditHistory(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $guide = $this->service->findGuideScope($id);

        if (!$guide) {
            return response()->json(['message' => 'Guia GRE no encontrada'], 404);
        }

        if ((int) $guide->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $traceabilityGate = $this->ensureTraceabilityFeatureEnabled(
            $companyId,
            $guide->branch_id !== null ? (int) $guide->branch_id : null
        );
        if ($traceabilityGate !== null) {
            return $traceabilityGate;
        }

        $limit = min((int) $request->query('limit', 50), 500);
        $history = $this->auditService->getDocumentHistoryByScope($id, 'GRE_GUIDE', 'GRE', $limit);

        return response()->json([
            'guide_id' => $id,
            'count' => count($history),
            'logs' => $history,
        ]);
    }

    public function store(StoreGreGuideRequest $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) ($request->input('company_id') ?? $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        try {
            $guide = $this->service->create($companyId, $request->validated(), (int) $authUser->id);
            return response()->json(['message' => 'Guia GRE creada', 'data' => $guide], 201);
        } catch (TaxBridgeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus());
        }
    }

    public function update(UpdateGreGuideRequest $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) ($request->input('company_id') ?? $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        try {
            $guide = $this->service->update($companyId, $id, $request->validated(), (int) $authUser->id);
            return response()->json(['message' => 'Guia GRE actualizada', 'data' => $guide], 200);
        } catch (TaxBridgeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus());
        }
    }

    public function send(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) ($request->input('company_id') ?? $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        try {
            $result = $this->service->send($companyId, $id, (int) $authUser->id, (string) ($authUser->username ?? ''));
            return response()->json([
                'message' => 'Guia procesada',
                'guide_id' => $id,
                'status' => $result['status'],
                'label' => $result['label'],
                'bridge_http_code' => $result['bridge_http_code'],
                'sunat_ticket' => $result['sunat_ticket'],
                'sunat_cdr_code' => $result['sunat_cdr_code'],
                'sunat_cdr_desc' => $result['sunat_cdr_desc'],
                'response' => $result['response'],
                'debug' => $result['debug'],
            ], 200);
        } catch (TaxBridgeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus());
        }
    }

    public function statusTicket(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) ($request->input('company_id') ?? $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        try {
            $result = $this->service->queryTicketStatus($companyId, $id, (int) $authUser->id, (string) ($authUser->username ?? ''));
            return response()->json([
                'message' => 'Ticket procesado',
                'guide_id' => $id,
                'status' => $result['status'],
                'label' => $result['label'],
                'bridge_http_code' => $result['bridge_http_code'],
                'sunat_ticket' => $result['sunat_ticket'],
                'sunat_cdr_code' => $result['sunat_cdr_code'],
                'sunat_cdr_desc' => $result['sunat_cdr_desc'],
                'response' => $result['response'],
                'debug' => $result['debug'],
            ], 200);
        } catch (TaxBridgeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus());
        }
    }

    public function cancel(CancelGreGuideRequest $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) ($request->input('company_id') ?? $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        try {
            $guide = $this->service->cancel($companyId, $id, (string) $request->input('reason'), (int) $authUser->id);
            return response()->json(['message' => 'Guia anulada', 'data' => $guide], 200);
        } catch (TaxBridgeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus());
        }
    }

    public function printable(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response('Invalid company scope', 403);
        }

        $format = in_array($request->query('format'), ['ticket', 'a4'], true)
            ? (string) $request->query('format')
            : 'a4';

        try {
            $html = $this->service->printableHtml($companyId, $id, $format);
            return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
        } catch (TaxBridgeException $e) {
            return response($e->getMessage(), $e->httpStatus());
        }
    }

    private function ensureTraceabilityFeatureEnabled(int $companyId, ?int $branchId)
    {
        $featureCode = 'SALES_TAX_BRIDGE_DEBUG_VIEW';

        $isEnabled = $this->service->isFeatureEnabledForContext($companyId, $branchId, $featureCode, false);
        if (!$isEnabled) {
            return response()->json([
                'message' => 'La trazabilidad de intentos está deshabilitada por configuración',
            ], 403);
        }

        return null;
    }
}
