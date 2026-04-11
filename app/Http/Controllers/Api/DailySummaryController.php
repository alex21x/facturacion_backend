<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sales\TaxBridge\DailySummaryService;
use App\Services\Sales\TaxBridge\TaxBridgeException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DailySummaryController extends Controller
{
    public function __construct(private DailySummaryService $dailySummaryService)
    {
    }

    // ── GET /sales/daily-summaries ────────────────────────────────────────────
    public function index(Request $request)
    {
        $authUser  = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $validator = Validator::make($request->all(), [
            'summary_type' => 'required|integer|in:1,3',
            'date'         => 'nullable|date_format:Y-m-d',
            'status'       => 'nullable|string|in:DRAFT,SENDING,SENT,ACCEPTED,REJECTED,ERROR',
            'page'         => 'nullable|integer|min:1',
            'per_page'     => 'nullable|integer|min:5|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $this->dailySummaryService->list(
            $companyId,
            (int) $request->query('summary_type'),
            $request->query('date'),
            $request->query('status'),
            (int) $request->query('page', 1),
            (int) $request->query('per_page', 30)
        );

        return response()->json($data, 200);
    }

    // ── GET /sales/daily-summaries/eligible-documents ────────────────────────
    public function eligibleDocuments(Request $request)
    {
        $authUser  = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $validator = Validator::make($request->all(), [
            'summary_type' => 'required|integer|in:1,3',
            'date'         => 'required|date_format:Y-m-d',
            'branch_id'    => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $branchId = $request->query('branch_id') !== null
            ? (int) $request->query('branch_id')
            : null;

        $docs = $this->dailySummaryService->eligibleDocuments(
            $companyId,
            (int) $request->query('summary_type'),
            (string) $request->query('date'),
            $branchId
        );

        return response()->json(['data' => $docs], 200);
    }

    // ── GET /sales/daily-summaries/{id} ──────────────────────────────────────
    public function show(Request $request, int $id)
    {
        $authUser  = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $summary = $this->dailySummaryService->show($companyId, $id);

        if ($summary === null) {
            return response()->json(['message' => 'Daily summary not found'], 404);
        }

        return response()->json($summary, 200);
    }

    // ── POST /sales/daily-summaries ───────────────────────────────────────────
    public function store(Request $request)
    {
        $authUser  = $request->attributes->get('auth_user');
        $companyId = (int) ($request->input('company_id') ?? $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $validator = Validator::make($request->all(), [
            'summary_type' => 'required|integer|in:1,3',
            'summary_date' => 'required|date_format:Y-m-d',
            'document_ids' => 'required|array|min:1',
            'document_ids.*' => 'integer|min:1',
            'branch_id'    => 'nullable|integer',
            'notes'        => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $payload = $validator->validated();

        try {
            $summary = $this->dailySummaryService->create(
                $companyId,
                (int) $payload['summary_type'],
                (string) $payload['summary_date'],
                (array) $payload['document_ids'],
                (int) $authUser->id,
                isset($payload['branch_id']) ? (int) $payload['branch_id'] : null,
                $payload['notes'] ?? null
            );

            return response()->json([
                'message' => 'Resumen diario creado',
                'data'    => $summary,
            ], 201);
        } catch (TaxBridgeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus());
        }
    }

    // ── DELETE /sales/daily-summaries/{id} ───────────────────────────────────
    public function destroy(Request $request, int $id)
    {
        $authUser  = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        try {
            $this->dailySummaryService->deleteDraft($companyId, $id);
            return response()->json(['message' => 'Resumen borrador eliminado'], 200);
        } catch (TaxBridgeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus());
        }
    }

    public function removeDocument(Request $request, int $id, int $documentId)
    {
        $authUser  = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        try {
            $result = $this->dailySummaryService->removeDocumentFromEditableSummary($companyId, $id, $documentId);

            return response()->json([
                'message' => $result['deleted']
                    ? 'Comprobante retirado y resumen eliminado por quedar vacio'
                    : 'Comprobante retirado del resumen',
                'deleted' => $result['deleted'],
                'summary_id' => $result['summary_id'],
                'remaining_items' => $result['remaining_items'],
            ], 200);
        } catch (TaxBridgeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus());
        }
    }

    // ── PUT /sales/daily-summaries/{id}/send ─────────────────────────────────
    public function send(Request $request, int $id)
    {
        $authUser  = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        try {
            $result = $this->dailySummaryService->send($companyId, $id);

            return response()->json([
                'message'           => 'Resumen procesado',
                'summary_id'        => $id,
                'status'            => $result['status'],
                'label'             => $result['label'],
                'bridge_http_code'  => $result['bridge_http_code'],
                'sunat_ticket'      => $result['sunat_ticket'],
                'sunat_cdr_code'    => $result['sunat_cdr_code'],
                'sunat_cdr_desc'    => $result['sunat_cdr_desc'],
                'sunat_error_code'  => $result['sunat_error_code'] ?? null,
                'sunat_error_message' => $result['sunat_error_message'] ?? null,
                'response'          => $result['response'],
                'debug'             => $result['debug'],
            ], 200);
        } catch (TaxBridgeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus());
        }
    }
}
