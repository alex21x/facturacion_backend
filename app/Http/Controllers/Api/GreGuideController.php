<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sales\TaxBridge\GreGuideService;
use App\Services\Sales\TaxBridge\TaxBridgeAuditService;
use App\Services\Sales\TaxBridge\TaxBridgeException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class GreGuideController extends Controller
{
    public function __construct(
        private GreGuideService $service,
        private TaxBridgeAuditService $auditService
    )
    {
    }

    public function index(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'nullable|string|max:30',
            'issue_date' => 'nullable|date_format:Y-m-d',
            'search' => 'nullable|string|max:120',
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
            $branchExists = DB::table('core.branches')
                ->where('id', $branchId)
                ->where('company_id', $companyId)
                ->where('status', 1)
                ->exists();

            if (!$branchExists) {
                return response()->json(['message' => 'Invalid branch scope'], 422);
            }
        }

        return response()->json($this->service->lookups($companyId, $branchId), 200);
    }

    public function ubigeos(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'q' => 'required|string|min:2|max:80',
            'limit' => 'nullable|integer|min:1|max:60',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $q = (string) $request->query('q');
        $limit = (int) $request->query('limit', 30);

        return response()->json([
            'data' => $this->service->searchUbigeos($q, $limit),
        ], 200);
    }

    public function prefillFromDocument(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $validator = Validator::make($request->all(), [
            'document_id' => 'nullable|integer|min:1',
            'series' => 'nullable|string|max:8',
            'number' => 'nullable|integer|min:1',
            'document_kind' => 'nullable|string|in:INVOICE,RECEIPT',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

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

        $guide = DB::table('sales.gre_guides')
            ->where('id', $id)
            ->select('id', 'company_id', 'branch_id')
            ->first();

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

    public function store(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) ($request->input('company_id') ?? $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $validator = Validator::make($request->all(), [
            'branch_id' => 'nullable|integer|min:1',
            'guide_type' => 'required|string|in:REMITENTE,TRANSPORTISTA',
            'series' => 'required|string|max:8',
            'issue_date' => 'required|date_format:Y-m-d',
            'transfer_date' => 'nullable|date_format:Y-m-d',
            'motivo_traslado' => 'required|string|max:4',
            'transport_mode_code' => 'required|string|in:01,02',
            'weight_kg' => 'required|numeric|gt:0',
            'packages_count' => 'required|integer|min:1|max:100000',
            'partida_ubigeo' => ['required', 'regex:/^\d{6}$/'],
            'punto_partida' => 'required|string|max:500',
            'llegada_ubigeo' => ['required', 'regex:/^\d{6}$/'],
            'punto_llegada' => 'required|string|max:500',
            'related_document_id' => 'nullable|integer|min:1',
            'notes' => 'nullable|string|max:1000',
            'destinatario' => 'required|array',
            'transporter' => 'nullable|array',
            'vehicle' => 'nullable|array',
            'driver' => 'nullable|array',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string|max:500',
            'items.*.qty' => 'required|numeric|min:0.0001',
            'items.*.code' => 'nullable|string|max:100',
            'items.*.unit' => 'nullable|string|max:30',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $guide = $this->service->create($companyId, $validator->validated(), (int) $authUser->id);
            return response()->json(['message' => 'Guia GRE creada', 'data' => $guide], 201);
        } catch (TaxBridgeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus());
        }
    }

    public function update(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) ($request->input('company_id') ?? $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $validator = Validator::make($request->all(), [
            'guide_type' => 'nullable|string|in:REMITENTE,TRANSPORTISTA',
            'issue_date' => 'nullable|date_format:Y-m-d',
            'transfer_date' => 'nullable|date_format:Y-m-d',
            'motivo_traslado' => 'nullable|string|max:4',
            'transport_mode_code' => 'nullable|string|in:01,02',
            'weight_kg' => 'nullable|numeric|gt:0',
            'packages_count' => 'nullable|integer|min:1|max:100000',
            'partida_ubigeo' => ['nullable', 'regex:/^\d{6}$/'],
            'punto_partida' => 'nullable|string|max:500',
            'llegada_ubigeo' => ['nullable', 'regex:/^\d{6}$/'],
            'punto_llegada' => 'nullable|string|max:500',
            'related_document_id' => 'nullable|integer|min:1',
            'notes' => 'nullable|string|max:1000',
            'destinatario' => 'nullable|array',
            'transporter' => 'nullable|array',
            'vehicle' => 'nullable|array',
            'driver' => 'nullable|array',
            'items' => 'nullable|array|min:1',
            'items.*.description' => 'required_with:items|string|max:500',
            'items.*.qty' => 'required_with:items|numeric|min:0.0001',
            'items.*.code' => 'nullable|string|max:100',
            'items.*.unit' => 'nullable|string|max:30',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $guide = $this->service->update($companyId, $id, $validator->validated(), (int) $authUser->id);
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

    public function cancel(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) ($request->input('company_id') ?? $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
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

        if ($branchId !== null) {
            $branchRow = DB::table('appcfg.branch_feature_toggles')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('feature_code', $featureCode)
                ->select('is_enabled')
                ->first();

            if ($branchRow && isset($branchRow->is_enabled)) {
                if (!(bool) $branchRow->is_enabled) {
                    return response()->json([
                        'message' => 'La trazabilidad de intentos está deshabilitada por configuración',
                    ], 403);
                }

                return null;
            }
        }

        $companyRow = DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->where('feature_code', $featureCode)
            ->select('is_enabled')
            ->first();

        if (!$companyRow || !(bool) ($companyRow->is_enabled ?? false)) {
            return response()->json([
                'message' => 'La trazabilidad de intentos está deshabilitada por configuración',
            ], 403);
        }

        return null;
    }
}
