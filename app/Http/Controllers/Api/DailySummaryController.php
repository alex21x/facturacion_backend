<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DailySummary\EligibleDailySummaryDocumentsRequest;
use App\Http\Requests\DailySummary\IndexDailySummaryRequest;
use App\Http\Requests\DailySummary\StoreDailySummaryRequest;
use App\Services\Sales\TaxBridge\DailySummaryService;
use App\Services\Sales\TaxBridge\TaxBridgeException;
use Illuminate\Http\Request;

class DailySummaryController extends Controller
{
    public function __construct(private DailySummaryService $dailySummaryService)
    {
    }

    // ── GET /sales/daily-summaries ────────────────────────────────────────────
    public function index(IndexDailySummaryRequest $request)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

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
    public function eligibleDocuments(EligibleDailySummaryDocumentsRequest $request)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

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
        $companyId = (int) $request->attributes->get('resolved_company_id');

        $summary = $this->dailySummaryService->show($companyId, $id);

        if ($summary === null) {
            return response()->json(['message' => 'Daily summary not found'], 404);
        }

        return response()->json($summary, 200);
    }

    // ── POST /sales/daily-summaries ───────────────────────────────────────────
    public function store(StoreDailySummaryRequest $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');

        $payload = $request->validated();

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
        $companyId = (int) $request->attributes->get('resolved_company_id');

        try {
            $this->dailySummaryService->deleteDraft($companyId, $id);
            return response()->json(['message' => 'Resumen borrador eliminado'], 200);
        } catch (TaxBridgeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus());
        }
    }

    public function removeDocument(Request $request, int $id, int $documentId)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

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
        $companyId = (int) $request->attributes->get('resolved_company_id');

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

    public function statusTicket(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');

        try {
            $result = $this->dailySummaryService->queryTicketStatus($companyId, $id, (int) $authUser->id, (string) ($authUser->username ?? ''));

            return response()->json([
                'message' => 'Ticket procesado',
                'summary_id' => $id,
                'status' => $result['status'],
                'label' => $result['label'],
                'bridge_http_code' => $result['bridge_http_code'],
                'sunat_ticket' => $result['sunat_ticket'],
                'sunat_cdr_code' => $result['sunat_cdr_code'],
                'sunat_cdr_desc' => $result['sunat_cdr_desc'],
                'sunat_error_code' => $result['sunat_error_code'] ?? null,
                'sunat_error_message' => $result['sunat_error_message'] ?? null,
                'response' => $result['response'],
                'debug' => $result['debug'],
            ], 200);
        } catch (TaxBridgeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus());
        }
    }
}
