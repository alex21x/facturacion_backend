<?php

namespace App\Services\Sales\TaxBridge;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * DailySummaryService
 *
 * Handles Resumen Diario for BOLETAs:
 *   - summary_type 1 → RC (Resumen de Comprobantes / Declaración)  → bridge: send_resumenDiario
 *   - summary_type 3 → RA (Resumen de Anulaciones  / Anulación)    → bridge: send_anulacion
 */
class DailySummaryService
{
    // ─── summary_type constants ────────────────────────────────────────────────
    public const TYPE_DECLARATION  = 1;   // RC
    public const TYPE_CANCELLATION = 3;   // RA

    // ─── status constants ─────────────────────────────────────────────────────
    public const STATUS_DRAFT    = 'DRAFT';
    public const STATUS_SENDING  = 'SENDING';
    public const STATUS_SENT     = 'SENT';
    public const STATUS_ACCEPTED = 'ACCEPTED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_ERROR    = 'ERROR';

    public function __construct(
        private TaxBridgeService $taxBridgeService,
        private TaxBridgeAuditService $auditService
    )
    {
    }

    // ─────────────────────────────────────────────────────────────────────────
    // QUERY
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Paginated list of summaries for a company.
     */
    public function list(
        int $companyId,
        int $summaryType,
        ?string $date = null,
        ?string $status = null,
        int $page = 1,
        int $perPage = 30
    ): array {
        $query = DB::table('sales.daily_summaries as ds')
            ->where('ds.company_id', $companyId)
            ->where('ds.summary_type', $summaryType)
            ->orderByDesc('ds.summary_date')
            ->orderByDesc('ds.id');

        if ($date !== null && $date !== '') {
            $query->whereDate('ds.summary_date', $date);
        }

        if ($status !== null && $status !== '') {
            $query->where('ds.status', strtoupper($status));
        }

        $total  = (clone $query)->count();
        $offset = ($page - 1) * $perPage;
        $rows   = $query->offset($offset)->limit($perPage)
            ->select(
                'ds.id',
                'ds.summary_type',
                'ds.summary_date',
                'ds.correlation_number',
                'ds.identifier',
                'ds.status',
                'ds.sunat_ticket',
                'ds.sunat_cdr_code',
                'ds.sunat_cdr_desc',
                'ds.notes',
                'ds.sent_at',
                'ds.created_at',
                'ds.updated_at'
            )
            ->get();

        // attach item count
        $rows = $rows->map(function ($row) {
            $row->item_count = DB::table('sales.daily_summary_items')
                ->where('summary_id', $row->id)
                ->count();
            return $row;
        });

        return [
            'data' => $rows->values()->all(),
            'meta' => [
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => $total,
                'last_page' => (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * Full detail of a single summary including its items.
     */
    public function show(int $companyId, int $summaryId): ?array
    {
        $summary = DB::table('sales.daily_summaries')
            ->where('id', $summaryId)
            ->where('company_id', $companyId)
            ->first();

        if (!$summary) {
            return null;
        }

        $items = DB::table('sales.daily_summary_items as dsi')
            ->join('sales.commercial_documents as cd', 'cd.id', '=', 'dsi.document_id')
            ->leftJoin('sales.customers as cu', 'cu.id', '=', 'cd.customer_id')
            ->where('dsi.summary_id', $summaryId)
            ->select(
                'dsi.id as item_id',
                'dsi.document_id',
                'dsi.item_status',
                'cd.document_kind',
                'cd.series',
                'cd.number',
                'cd.issue_at',
                'cd.status as doc_status',
                'cd.total',
                'cd.metadata',
                DB::raw("COALESCE(cu.legal_name, cu.first_name || ' ' || cu.last_name, '') as customer_name")
            )
            ->orderBy('cd.series')
            ->orderBy('cd.number')
            ->get()
            ->map(function ($item) {
                $meta = json_decode((string) ($item->metadata ?? '{}'), true);
                $item->sunat_status = is_array($meta) ? ($meta['sunat_status'] ?? null) : null;
                $item->sunat_void_status = is_array($meta) ? ($meta['sunat_void_status'] ?? null) : null;
                unset($item->metadata);
                return $item;
            });

        $rawResponse = $summary->raw_response;
        if (is_string($rawResponse)) {
            $rawResponse = json_decode($rawResponse, true);
        }

        $diagnostic = $this->summarizeSummaryDiagnostic($rawResponse, (string) ($summary->sunat_cdr_code ?? ''), (string) ($summary->sunat_cdr_desc ?? ''));

        $requestDebug = $this->buildSummaryRequestDebug($companyId, $summary);

        return [
            'id'                 => $summary->id,
            'company_id'         => $summary->company_id,
            'branch_id'          => $summary->branch_id,
            'summary_type'       => (int) $summary->summary_type,
            'summary_date'       => $summary->summary_date,
            'correlation_number' => $summary->correlation_number,
            'identifier'         => $summary->identifier,
            'status'             => $summary->status,
            'sunat_ticket'       => $summary->sunat_ticket,
            'sunat_cdr_code'     => $summary->sunat_cdr_code,
            'sunat_cdr_desc'     => $summary->sunat_cdr_desc,
            'bridge_endpoint'    => $summary->bridge_endpoint,
            'bridge_http_code'   => $summary->bridge_http_code,
            'sunat_error_code'   => $diagnostic['code'],
            'sunat_error_message'=> $diagnostic['message'],
            'request_debug'      => $requestDebug,
            'raw_response'       => $rawResponse,
            'notes'              => $summary->notes,
            'sent_at'            => $summary->sent_at,
            'created_at'         => $summary->created_at,
            'updated_at'         => $summary->updated_at,
            'items'              => $items->values()->all(),
        ];
    }

    /**
     * Returns RECEIPT documents eligible to be included in a new summary.
     *
     *  type 1 (RC): ISSUED receipts not yet in a pending/sent/accepted summary,
     *               whose sunat_status is PENDING_MANUAL or null (not yet sent individually).
     *  type 3 (RA): RECEIPTs already accepted by SUNAT and pending cancellation via summary,
     *               plus legacy VOID/VOIDED receipts, not yet in a cancellation summary.
     */
    public function eligibleDocuments(
        int $companyId,
        int $summaryType,
        string $date,
        ?int $branchId = null
    ): array {
        // document_ids already assigned to a non-DRAFT summary of the same type
        $lockedDocIds = DB::table('sales.daily_summary_items as dsi')
            ->join('sales.daily_summaries as ds', 'ds.id', '=', 'dsi.summary_id')
            ->where('ds.company_id', $companyId)
            ->where('ds.summary_type', $summaryType)
            ->whereIn('ds.status', [self::STATUS_DRAFT, self::STATUS_SENDING, self::STATUS_SENT, self::STATUS_ACCEPTED])
            ->pluck('dsi.document_id')
            ->all();

        $query = DB::table('sales.commercial_documents as cd')
            ->leftJoin('sales.customers as cu', 'cu.id', '=', 'cd.customer_id')
            ->where('cd.company_id', $companyId)
            ->where('cd.document_kind', 'RECEIPT')
            ->whereDate('cd.issue_at', $date);

        if ($branchId !== null) {
            $query->where('cd.branch_id', $branchId);
        }

        if (!empty($lockedDocIds)) {
            $query->whereNotIn('cd.id', $lockedDocIds);
        }

        if ($summaryType === self::TYPE_DECLARATION) {
            // ISSUED boletas whose sunat_status is not yet a final accepted state
            $query->where('cd.status', 'ISSUED');
            $query->where(function ($q) {
                $q->whereRaw("(cd.metadata->>'sunat_status') IS NULL")
                  ->orWhereRaw("cd.metadata->>'sunat_status' = ''")
                  ->orWhereRaw("cd.metadata->>'sunat_status' = 'PENDING_MANUAL'")
                  ->orWhereRaw("cd.metadata->>'sunat_status' = 'PENDING'")
                  ->orWhereRaw("cd.metadata->>'sunat_status' = 'NOT_SENT'");
            });
        } else {
            // RECEIPTs eligible for RA cancellation summary.
            $query->where(function ($q) {
                $q->whereIn('cd.status', ['VOID', 'VOIDED'])
                  ->orWhere(function ($q2) {
                      $q2->where('cd.status', 'ISSUED')
                         ->whereRaw("UPPER(COALESCE(cd.metadata->>'sunat_status', '')) IN ('ACCEPTED', 'SENT_BY_SUMMARY')");
                  });
            });
        }

        $rows = $query
            ->select(
                'cd.id',
                'cd.series',
                'cd.number',
                'cd.issue_at',
                'cd.status',
                'cd.total',
                'cd.metadata',
                DB::raw("COALESCE(cu.legal_name, cu.first_name || ' ' || cu.last_name, '') as customer_name")
            )
            ->orderBy('cd.series')
            ->orderBy('cd.number')
            ->get()
            ->map(function ($doc) {
                $meta = json_decode((string) ($doc->metadata ?? '{}'), true);
                $doc->sunat_status = is_array($meta) ? ($meta['sunat_status'] ?? null) : null;
                unset($doc->metadata);
                return $doc;
            });

        return $rows->values()->all();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WRITE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Create a new DRAFT summary with the given document IDs.
     */
    public function create(
        int $companyId,
        int $summaryType,
        string $summaryDate,
        array $documentIds,
        int $createdBy,
        ?int $branchId = null,
        ?string $notes = null
    ): array {
        if (!in_array($summaryType, [self::TYPE_DECLARATION, self::TYPE_CANCELLATION], true)) {
            throw new TaxBridgeException('summary_type must be 1 (declaration) or 3 (cancellation)', 422);
        }

        if (empty($documentIds)) {
            throw new TaxBridgeException('At least one document must be selected', 422);
        }

        $documentIds = array_values(array_unique(array_map('intval', $documentIds)));

        // Validate all supplied IDs are eligible
        $validCount = DB::table('sales.commercial_documents')
            ->where('company_id', $companyId)
            ->whereIn('id', $documentIds)
            ->where('document_kind', 'RECEIPT')
            ->count();

        if ($validCount !== count($documentIds)) {
            throw new TaxBridgeException('One or more selected documents are not valid RECEIPTs for this company', 422);
        }

        // Check none are already locked in an active summary
        $alreadyLocked = DB::table('sales.daily_summary_items as dsi')
            ->join('sales.daily_summaries as ds', 'ds.id', '=', 'dsi.summary_id')
            ->where('ds.company_id', $companyId)
            ->where('ds.summary_type', $summaryType)
            ->whereIn('ds.status', [self::STATUS_DRAFT, self::STATUS_SENDING, self::STATUS_SENT, self::STATUS_ACCEPTED])
            ->whereIn('dsi.document_id', $documentIds)
            ->count();

        if ($alreadyLocked > 0) {
            throw new TaxBridgeException('One or more selected documents are already assigned to an active daily summary', 422);
        }

        // Determine correlation number (sequential per company+type+date)
        $correlationNumber = (int) DB::table('sales.daily_summaries')
            ->where('company_id', $companyId)
            ->where('summary_type', $summaryType)
            ->whereDate('summary_date', $summaryDate)
            ->max('correlation_number') + 1;

        $prefix     = $summaryType === self::TYPE_DECLARATION ? 'RC' : 'RA';
        $datePart   = str_replace('-', '', $summaryDate);  // YYYYMMDD
        $identifier = sprintf('%s-%s-%03d', $prefix, $datePart, $correlationNumber);

        $summaryId = DB::table('sales.daily_summaries')->insertGetId([
            'company_id'         => $companyId,
            'branch_id'          => $branchId,
            'summary_type'       => $summaryType,
            'summary_date'       => $summaryDate,
            'correlation_number' => $correlationNumber,
            'identifier'         => $identifier,
            'status'             => self::STATUS_DRAFT,
            'notes'              => $notes,
            'created_by'         => $createdBy,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $itemStatus = $summaryType === self::TYPE_DECLARATION ? 1 : 3;
        $now = now();
        $inserts = array_map(fn($docId) => [
            'summary_id'  => $summaryId,
            'document_id' => $docId,
            'item_status' => $itemStatus,
            'created_at'  => $now,
        ], $documentIds);

        DB::table('sales.daily_summary_items')->insert($inserts);

        foreach ($documentIds as $docId) {
            $this->updateDocumentSummaryMetadata($companyId, (int) $docId, $summaryType, (int) $summaryId);
        }

        return $this->show($companyId, $summaryId) ?? [];
    }

    /**
     * Appends one RECEIPT document into the current open (DRAFT) summary of the given type.
     * If no open summary exists, creates one automatically.
     */
    public function appendDocumentToOpenSummary(
        int $companyId,
        int $summaryType,
        int $documentId,
        int $createdBy,
        ?int $branchId = null,
        ?string $summaryDate = null
    ): array {
        if (!in_array($summaryType, [self::TYPE_DECLARATION, self::TYPE_CANCELLATION], true)) {
            throw new TaxBridgeException('summary_type must be 1 (declaration) or 3 (cancellation)', 422);
        }

        $document = DB::table('sales.commercial_documents')
            ->where('id', $documentId)
            ->where('company_id', $companyId)
            ->select('id', 'document_kind', 'status', 'branch_id', 'issue_at', 'metadata')
            ->first();

        if (!$document) {
            throw new TaxBridgeException('Commercial document not found', 404);
        }

        if (strtoupper((string) ($document->document_kind ?? '')) !== 'RECEIPT') {
            throw new TaxBridgeException('Only RECEIPT documents can be grouped in daily summary', 422);
        }

        $documentStatus = strtoupper((string) ($document->status ?? ''));
        $documentMeta = json_decode((string) ($document->metadata ?? '{}'), true);
        $documentMeta = is_array($documentMeta) ? $documentMeta : [];
        $sunatStatus = strtoupper(trim((string) ($documentMeta['sunat_status'] ?? '')));

        if ($summaryType === self::TYPE_DECLARATION && $documentStatus !== 'ISSUED') {
            throw new TaxBridgeException('Declaration summary only accepts RECEIPT in ISSUED status', 422);
        }

        if ($summaryType === self::TYPE_CANCELLATION) {
            $isLegacyVoided = in_array($documentStatus, ['VOID', 'VOIDED'], true);
            $isAcceptedIssued = $documentStatus === 'ISSUED' && in_array($sunatStatus, ['ACCEPTED', 'SENT_BY_SUMMARY'], true);

            if (!$isLegacyVoided && !$isAcceptedIssued) {
                throw new TaxBridgeException('Cancellation summary only accepts RECEIPT accepted by SUNAT (or legacy VOID status)', 422);
            }
        }

        $existingSummary = DB::table('sales.daily_summary_items as dsi')
            ->join('sales.daily_summaries as ds', 'ds.id', '=', 'dsi.summary_id')
            ->where('ds.company_id', $companyId)
            ->where('ds.summary_type', $summaryType)
            ->whereIn('ds.status', [self::STATUS_DRAFT, self::STATUS_SENDING, self::STATUS_SENT, self::STATUS_ACCEPTED])
            ->where('dsi.document_id', $documentId)
            ->select('ds.id')
            ->first();

        if ($existingSummary) {
            return $this->show($companyId, (int) $existingSummary->id) ?? [];
        }

        $effectiveBranchId = $branchId ?? ($document->branch_id !== null ? (int) $document->branch_id : null);
        $effectiveSummaryDate = $summaryDate !== null && trim($summaryDate) !== ''
            ? trim($summaryDate)
            : now('America/Lima')->toDateString();

        $openDraftSummary = DB::table('sales.daily_summaries')
            ->where('company_id', $companyId)
            ->where('summary_type', $summaryType)
            ->where('status', self::STATUS_DRAFT)
            ->when($effectiveBranchId !== null, function ($query) use ($effectiveBranchId) {
                $query->where('branch_id', $effectiveBranchId);
            }, function ($query) {
                $query->whereNull('branch_id');
            })
            ->orderByDesc('id')
            ->first();

        if (!$openDraftSummary) {
            return $this->create(
                $companyId,
                $summaryType,
                $effectiveSummaryDate,
                [$documentId],
                $createdBy,
                $effectiveBranchId
            );
        }

        DB::table('sales.daily_summary_items')->insert([
            'summary_id' => (int) $openDraftSummary->id,
            'document_id' => $documentId,
            'item_status' => $summaryType === self::TYPE_DECLARATION ? 1 : 3,
            'created_at' => now(),
        ]);

        DB::table('sales.daily_summaries')
            ->where('id', (int) $openDraftSummary->id)
            ->update(['updated_at' => now()]);

        $this->updateDocumentSummaryMetadata($companyId, $documentId, $summaryType, (int) $openDraftSummary->id);

        return $this->show($companyId, (int) $openDraftSummary->id) ?? [];
    }

    /**
     * Delete a DRAFT summary (only if still in DRAFT status).
     */
    public function deleteDraft(int $companyId, int $summaryId): void
    {
        $summary = DB::table('sales.daily_summaries')
            ->where('id', $summaryId)
            ->where('company_id', $companyId)
            ->first();

        if (!$summary) {
            throw new TaxBridgeException('Daily summary not found', 404);
        }

        if (!in_array($summary->status, [self::STATUS_DRAFT, self::STATUS_ERROR, self::STATUS_REJECTED], true)) {
            throw new TaxBridgeException('Only DRAFT, ERROR or REJECTED summaries can be deleted', 422);
        }

        $documentIds = DB::table('sales.daily_summary_items')
            ->where('summary_id', $summaryId)
            ->pluck('document_id')
            ->all();

        foreach ($documentIds as $documentId) {
            $this->clearDocumentSummaryMetadata($companyId, (int) $documentId, (int) $summary->summary_type, $summaryId);
        }

        DB::table('sales.daily_summary_items')->where('summary_id', $summaryId)->delete();
        DB::table('sales.daily_summaries')->where('id', $summaryId)->delete();
    }

    public function removeDocumentFromEditableSummary(int $companyId, int $summaryId, int $documentId): array
    {
        $summary = DB::table('sales.daily_summaries')
            ->where('id', $summaryId)
            ->where('company_id', $companyId)
            ->first();

        if (!$summary) {
            throw new TaxBridgeException('Daily summary not found', 404);
        }

        if (!in_array($summary->status, [self::STATUS_DRAFT, self::STATUS_ERROR, self::STATUS_REJECTED], true)) {
            throw new TaxBridgeException('Only DRAFT, ERROR or REJECTED summaries can be edited', 422);
        }

        $deleted = DB::table('sales.daily_summary_items')
            ->where('summary_id', $summaryId)
            ->where('document_id', $documentId)
            ->delete();

        if ($deleted === 0) {
            throw new TaxBridgeException('Document is not assigned to the selected summary', 404);
        }

        $this->clearDocumentSummaryMetadata($companyId, $documentId, (int) $summary->summary_type, $summaryId);

        $remaining = (int) DB::table('sales.daily_summary_items')
            ->where('summary_id', $summaryId)
            ->count();

        if ($remaining === 0) {
            DB::table('sales.daily_summaries')->where('id', $summaryId)->delete();

            return [
                'deleted' => true,
                'summary_id' => $summaryId,
                'remaining_items' => 0,
            ];
        }

        DB::table('sales.daily_summaries')
            ->where('id', $summaryId)
            ->update(['updated_at' => now()]);

        return [
            'deleted' => false,
            'summary_id' => $summaryId,
            'remaining_items' => $remaining,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TAX BRIDGE DISPATCH
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Send a daily summary to the tax bridge (RC or RA).
     * Returns a result array with status, bridge_http_code and response.
     */
    public function send(int $companyId, int $summaryId): array
    {
        $summary = DB::table('sales.daily_summaries')
            ->where('id', $summaryId)
            ->where('company_id', $companyId)
            ->first();

        if (!$summary) {
            throw new TaxBridgeException('Daily summary not found', 404);
        }

        $summaryStatus = (string) $summary->status;
        $summaryTicket = $this->normalizeSunatTicketValue($summary->sunat_ticket ?? null);
        $canResendSentWithoutTicket = $summaryStatus === self::STATUS_SENT
            && $summaryTicket === null;

        if (!in_array($summaryStatus, [self::STATUS_DRAFT, self::STATUS_ERROR, self::STATUS_REJECTED], true) && !$canResendSentWithoutTicket) {
            throw new TaxBridgeException(
                'Summary must be in DRAFT, ERROR or REJECTED status to send (or SENT without SUNAT ticket). Current: ' . $summary->status,
                422
            );
        }

        $responseStartAt = null;
        $payloadJson = '{}';
        $endpoint = '';

        try {
            $branchId = $summary->branch_id !== null ? (int) $summary->branch_id : null;
            $config   = $this->resolveConfig($companyId, $branchId);

            if (!$config['enabled']) {
                throw new TaxBridgeException('Tax bridge is not enabled in AppCfg', 422);
            }

            if ($config['raw_base_url'] === '') {
                throw new TaxBridgeException('Tax bridge endpoint URL is not configured', 422);
            }

            $summaryType = (int) $summary->summary_type;
            $bridgeMethod = $summaryType === self::TYPE_DECLARATION ? 'send_resumenDiario' : 'send_anulacion';

            $endpoint = $this->resolveBridgeEndpoint($config['raw_base_url'], $bridgeMethod);
            if ($endpoint === '') {
                throw new TaxBridgeException("Tax bridge endpoint for {$bridgeMethod} could not be resolved", 422);
            }

            $payload = $this->buildPayload($companyId, $summary, $config);
            $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($payloadJson)) {
                $payloadJson = '{}';
            }

            DB::table('sales.daily_summaries')
                ->where('id', $summaryId)
                ->update([
                    'status'          => self::STATUS_SENDING,
                    'bridge_endpoint' => $endpoint,
                    'sent_at'         => now(),
                    'updated_at'      => now(),
                ]);

            $httpReq = Http::timeout((int) $config['timeout_seconds'])
                ->acceptJson()
                ->withHeaders($this->bridgeRequestHeaders());

            if ($config['auth_scheme'] === 'bearer' && $config['token'] !== '') {
                $httpReq = $httpReq->withToken($config['token']);
            }

            $responseStartAt = microtime(true);
            $response = $httpReq->asForm()->post($endpoint, ['datosJSON' => $payloadJson]);

            $raw     = (string) $response->body();
            $decoded = json_decode($raw, true);
            if (is_string($decoded)) {
                $nested = json_decode($decoded, true);
                if (is_array($nested)) {
                    $decoded = $nested;
                }
            }

            [$status, $label, $ticket, $cdrCode, $cdrDesc] = $this->interpretBridgeResponse(
                $response->successful(),
                $decoded,
                $raw
            );
            $responseTimeMs = $responseStartAt !== null ? round((microtime(true) - $responseStartAt) * 1000, 2) : null;
            $diagnostic = $this->summarizeSummaryDiagnostic($decoded, (string) ($cdrCode ?? ''), (string) ($cdrDesc ?? ''));

            $responseData = is_array($decoded) ? $decoded : ['raw' => substr($raw, 0, 2000)];
            if ($this->containsImunifyProtectionMarkers((string) ($diagnostic['message'] ?? '') . ' ' . $raw)) {
                $responseData['_waf_hint'] = 'El endpoint devolvio bloqueo de bot-protection (Imunify360).';
            }

            DB::table('sales.daily_summaries')
                ->where('id', $summaryId)
                ->update([
                    'status'          => $status,
                    'bridge_http_code' => $response->status(),
                    'raw_response'    => json_encode($responseData),
                    'sunat_ticket'    => $ticket,
                    'sunat_cdr_code'  => $cdrCode,
                    'sunat_cdr_desc'  => $cdrDesc,
                    'updated_at'      => now(),
                ]);

            $this->syncDocumentsAfterSummarySend(
                $companyId,
                (int) $summaryId,
                $summaryType,
                $status,
                $ticket,
                $cdrCode,
                $cdrDesc
            );

            Log::info('DailySummary sent', [
                'summary_id'   => $summaryId,
                'identifier'   => $summary->identifier,
                'status'       => $status,
                'http_code'    => $response->status(),
            ]);

            $this->auditService->logDispatch(
                $companyId,
                $branchId,
                $summaryType === self::TYPE_DECLARATION ? 'SUMMARY_RC' : 'SUMMARY_RA',
                null,
                $summaryType === self::TYPE_DECLARATION ? 'RC' : 'RA',
                (string) ($summary->summary_date ?? ''),
                (string) ($summary->identifier ?? $summaryId),
                [
                    'bridge_mode' => $config['bridge_mode'] ?? 'PRODUCTION',
                    'endpoint_url' => $endpoint,
                    'auth_scheme' => $config['auth_scheme'] ?? 'none',
                ],
                $payloadJson,
                substr($raw, 0, 100000),
                (int) $response->status(),
                $responseTimeMs,
                [
                    'code' => $diagnostic['code'],
                    'ticket' => $ticket,
                    'cdr_code' => $cdrCode,
                    'message' => $diagnostic['message'] ?: $cdrDesc,
                ],
                [
                    'sunat_status' => $status,
                    'error_kind' => !$response->successful() ? 'HTTP_ERROR' : null,
                    'attempt_number' => 1,
                    'is_retry' => false,
                    'is_manual' => true,
                ]
            );

            return [
                'status'           => $status,
                'label'            => $label,
                'bridge_http_code' => $response->status(),
                'sunat_ticket'     => $ticket,
                'sunat_cdr_code'   => $cdrCode,
                'sunat_cdr_desc'   => $cdrDesc,
                'sunat_error_code' => $diagnostic['code'],
                'sunat_error_message' => $diagnostic['message'],
                'response'         => $responseData,
                'debug'            => [
                    'endpoint'       => $endpoint,
                    'bridge_method'  => $bridgeMethod,
                    'method'         => 'POST',
                    'content_type'   => 'application/x-www-form-urlencoded',
                    'form_key'       => 'datosJSON',
                    'payload'        => $this->sanitizeSummaryPayloadForDebug($payload),
                    'payload_sha1'   => sha1($payloadJson),
                    'payload_length' => strlen($payloadJson),
                ],
            ];
        } catch (TaxBridgeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('DailySummary send failed', [
                'summary_id' => $summaryId,
                'identifier' => $summary->identifier ?? null,
                'exception'  => $e->getMessage(),
            ]);

            try {
                DB::table('sales.daily_summaries')
                    ->where('id', $summaryId)
                    ->update([
                        'status'       => self::STATUS_ERROR,
                        'raw_response' => json_encode(['error' => substr($e->getMessage(), 0, 500)]),
                        'updated_at'   => now(),
                    ]);
            } catch (\Throwable $updateError) {
                Log::error('DailySummary error state update failed', [
                    'summary_id' => $summaryId,
                    'exception'  => $updateError->getMessage(),
                ]);
            }

            $summaryType = (int) ($summary->summary_type ?? self::TYPE_DECLARATION);
            $this->auditService->logDispatch(
                $companyId,
                isset($summary->branch_id) && $summary->branch_id !== null ? (int) $summary->branch_id : null,
                $summaryType === self::TYPE_DECLARATION ? 'SUMMARY_RC' : 'SUMMARY_RA',
                null,
                $summaryType === self::TYPE_DECLARATION ? 'RC' : 'RA',
                (string) ($summary->summary_date ?? ''),
                (string) ($summary->identifier ?? $summaryId),
                [
                    'bridge_mode' => 'UNKNOWN',
                    'endpoint_url' => $endpoint,
                    'auth_scheme' => 'none',
                ],
                $payloadJson,
                null,
                null,
                null,
                [
                    'code' => null,
                    'ticket' => null,
                    'cdr_code' => null,
                    'message' => substr($e->getMessage(), 0, 400),
                ],
                [
                    'sunat_status' => self::STATUS_ERROR,
                    'error_kind' => 'NETWORK_ERROR',
                    'error_message' => $e->getMessage(),
                    'attempt_number' => 1,
                    'is_retry' => false,
                    'is_manual' => true,
                ]
            );

            throw new TaxBridgeException(
                'No se pudo preparar o enviar el resumen. Revisa la configuracion tributaria y los datos relacionados al comprobante.',
                500
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PAYLOAD BUILDERS
    // ─────────────────────────────────────────────────────────────────────────

    private function buildPayload(int $companyId, object $summary, array $config): array
    {
        $empresa = $this->buildEmpresaBlock($companyId, $config);

        $summaryType = (int) $summary->summary_type;
        $corrNumber  = (string) $summary->correlation_number;
        $dateHeader  = $this->buildSummaryDateHeader($summary);

        $items = DB::table('sales.daily_summary_items as dsi')
            ->join('sales.commercial_documents as cd', 'cd.id', '=', 'dsi.document_id')
            ->leftJoin('sales.customers as cu', 'cu.id', '=', 'cd.customer_id')
            ->leftJoin('sales.customer_types as ct', 'ct.id', '=', 'cu.customer_type_id')
            ->leftJoin('core.currencies as cur', 'cur.id', '=', 'cd.currency_id')
            ->where('dsi.summary_id', $summary->id)
            ->select(
                'cd.id as document_id',
                'cd.series',
                'cd.number',
                'cd.issue_at',
                'cd.subtotal',
                'cd.tax_total',
                'cd.total',
                'cd.metadata',
                'dsi.item_status',
                'cu.doc_number as customer_doc_number',
                'cu.legal_name as customer_legal_name',
                'cu.first_name as customer_first_name',
                'cu.last_name as customer_last_name',
                'ct.sunat_code as customer_doc_type_code',
                'cur.code as currency_code'
            )
            ->orderBy('cd.series')
            ->orderBy('cd.number')
            ->get();

        if ($summaryType === self::TYPE_DECLARATION) {
            return $this->buildRcPayload($empresa, $corrNumber, $items, $dateHeader);
        }

        return $this->buildRaPayload($empresa, $corrNumber, $items, $dateHeader);
    }

    /**
     * RC – Resumen de Comprobantes (Declaración, tipo 1)
     * Uses same payload structure as RA, without sending resumen = 1.
     */
    private function buildRcPayload(
        array $empresa,
        string $corrNumber,
        \Illuminate\Support\Collection $items,
        array $dateHeader
    ): array {
        return [
            'empresa' => $empresa,
            'cabecera' => $dateHeader,
            'fecha_generacion' => (string) ($dateHeader['fecha_generacion'] ?? ''),
            'fecha_resumen' => (string) ($dateHeader['fecha_resumen'] ?? ''),
            'correlativo_resumen' => str_pad($corrNumber, 3, '0', STR_PAD_LEFT),
            'resumenes' => $this->buildSummaryRows($items),
        ];
    }

    /**
     * RA – Resumen de Anulaciones (Anulación, tipo 3)
     * Each document becomes one detail row.
     */
    private function buildRaPayload(
        array $empresa,
        string $corrNumber,
        \Illuminate\Support\Collection $items,
        array $dateHeader
    ): array {
        return [
            'empresa' => $empresa,
            'cabecera' => $dateHeader,
            'fecha_generacion' => (string) ($dateHeader['fecha_generacion'] ?? ''),
            'fecha_resumen' => (string) ($dateHeader['fecha_resumen'] ?? ''),
            'resumen' => 1,
            'correlativo_resumen' => str_pad($corrNumber, 3, '0', STR_PAD_LEFT),
            'resumenes' => $this->buildSummaryRows($items),
        ];
    }

    private function buildSummaryDateHeader(object $summary): array
    {
        $summaryDate = trim((string) ($summary->summary_date ?? ''));
        $summaryDateYmd = $this->normalizeYmdDate($summaryDate);

        $fechaGeneracion = $summaryDateYmd !== null
            ? $summaryDateYmd
            : now('America/Lima')->subDays(3)->toDateString();

        $fechaResumen = now('America/Lima')->toDateString();

        return [
            'fecha_generacion' => $fechaGeneracion,
            'fecha_resumen' => $fechaResumen,
        ];
    }

    private function normalizeYmdDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        try {
            $dt = new \DateTimeImmutable($value, new \DateTimeZone('America/Lima'));
            return $dt->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function buildSummaryRows(\Illuminate\Support\Collection $items): array
    {
        $resumenes = $items->map(function ($item) {
            $meta = json_decode((string) ($item->metadata ?? '{}'), true);
            $meta = is_array($meta) ? $meta : [];
            $customerName = trim((string) ($item->customer_legal_name ?? ''));
            if ($customerName === '') {
                $customerName = trim((string) ($item->customer_first_name ?? '') . ' ' . (string) ($item->customer_last_name ?? ''));
            }

            return [
                'ruc_cliente' => trim((string) ($item->customer_doc_number ?? '')),
                'razon_social_cliente' => $customerName,
                'tipo_documento_cliente' => $this->resolveSummaryCustomerDocTypeCode($item->customer_doc_type_code),
                'tipo_operacion' => (string) ($meta['sunat_operation_type_code'] ?? '0101'),
                'tipo_documento' => '03',
                'serie' => (string) $item->series,
                'numero' => (string) $item->number,
                'fecha_emision' => date('Y-m-d', strtotime((string) $item->issue_at)),
                'tipo_moneda' => (string) ($item->currency_code ?? 'PEN'),
                'gravadas' => round((float) ($item->subtotal ?? 0), 2),
                'impuestos' => round((float) ($item->tax_total ?? 0), 2),
                'valor_venta' => round((float) ($item->total ?? 0) - (float) ($item->tax_total ?? 0), 2),
                'importe_venta' => round((float) ($item->total ?? 0), 2),
            ];
        })->values()->all();

        return $resumenes;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function buildEmpresaBlock(int $companyId, array $config): array
    {
        $companyRow = DB::table('core.companies as co')
            ->leftJoin('core.company_settings as cs', 'cs.company_id', '=', 'co.id')
            ->where('co.id', $companyId)
            ->select(
                'co.tax_id',
                'co.legal_name',
                'co.trade_name',
                'co.address',
                'cs.address as settings_address',
                'cs.phone',
                'cs.email',
                'cs.extra_data'
            )
            ->first();

        if (!$companyRow) {
            return [];
        }

        $extra = $companyRow->extra_data ? json_decode((string) $companyRow->extra_data, true) : [];
        $extra = is_array($extra) ? $extra : [];
        $address = trim((string) ($companyRow->settings_address ?: $companyRow->address ?: ''));

        $bridgeUser = trim((string) (($config['sunat_secondary_user'] ?? '') ?: ($config['sol_user'] ?? '')));
        $bridgePass = (string) (($config['sunat_secondary_pass'] ?? '') !== ''
            ? $config['sunat_secondary_pass']
            : ($config['sol_pass'] ?? ''));

        return [
            'ruc'            => (string) ($companyRow->tax_id ?? ''),
            'user'           => $bridgeUser,
            'pass'           => $bridgePass,
            'razon_social'   => (string) ($companyRow->legal_name ?? ''),
            'nombre_comercial' => (string) ($companyRow->trade_name ?? ''),
            'direccion'      => $address,
            'urbanizacion'   => trim((string) ($extra['urbanizacion'] ?? '')),
            'ubigeo'         => trim((string) ($extra['ubigeo'] ?? '')),
            'departamento'   => trim((string) ($extra['departamento'] ?? '')),
            'provincia'      => trim((string) ($extra['provincia'] ?? '')),
            'distrito'       => trim((string) ($extra['distrito'] ?? '')),
            'codigolocal'    => trim((string) ($config['codigolocal'] ?? '')),
            'telefono_fijo'  => trim((string) ($companyRow->phone ?? '')),
            'correo'         => trim((string) ($companyRow->email ?? '')),
            'envio_pse'      => trim((string) ($config['envio_pse'] ?? '')),
        ];
    }

    /**
     * Interprets the bridge response and returns [status, label, ticket, cdrCode, cdrDesc].
     */
    private function interpretBridgeResponse(bool $httpSuccess, $decoded, string $raw): array
    {
        $ticket  = null;
        $cdrCode = null;
        $cdrDesc = null;
        $message = $this->extractBridgeMessage($decoded, $raw);

        if (is_array($decoded)) {
            // Check ticket field first; fall back to numero (used by some legacy bridges)
            $rawTicket = $decoded['ticket'] ?? $decoded['numero'] ?? null;
            $ticket    = $this->normalizeSunatTicketValue($rawTicket);
            $cdrCode = isset($decoded['codRespuesta']) ? (string) $decoded['codRespuesta'] : null;
            $cdrDesc = isset($decoded['desRespuesta']) ? (string) $decoded['desRespuesta'] : null;

            // Normalize common alternatives
            $cdrCode = $cdrCode ?? (isset($decoded['cdr_code']) ? (string) $decoded['cdr_code'] : null);
            $cdrDesc = $cdrDesc ?? (isset($decoded['cdr_desc']) ? (string) $decoded['cdr_desc'] : null);
        }

        // Last resort: extract ticket embedded in the message text (e.g. "Enviado N° Ticket : 20260514001")
        if ($ticket === null && preg_match('/[Tt]icket\s*[:#]?\s*(\d{8,20})/', $message, $ticketMatch)) {
            $ticket = $this->normalizeSunatTicketValue($ticketMatch[1]);
        }

        if (!$httpSuccess) {
            return [self::STATUS_ERROR, 'Error HTTP', $ticket, $cdrCode, $cdrDesc];
        }

        $finalCdrCode = $this->resolveSummaryCdrCode($cdrCode, $message . ' ' . $raw);
        if ($finalCdrCode !== null) {
            if ($finalCdrCode === 0 || $finalCdrCode === 2223 || $finalCdrCode === 2323 || $finalCdrCode === 2324 || $finalCdrCode >= 4000) {
                return [self::STATUS_ACCEPTED, 'Resumen aceptado por SUNAT', $ticket, (string) $finalCdrCode, $cdrDesc];
            }

            if ($finalCdrCode >= 2000 && $finalCdrCode <= 3999) {
                return [self::STATUS_REJECTED, 'Resumen rechazado por SUNAT', $ticket, (string) $finalCdrCode, $cdrDesc];
            }
        }

        $resCode = null;
        if (is_array($decoded) && array_key_exists('res', $decoded)) {
            $resCode = (int) $decoded['res'];
        }

        $state = '';
        if (is_array($decoded)) {
            $state = strtoupper(trim((string) ($decoded['estado'] ?? $decoded['state'] ?? '')));
        }

        $hasBridgeErrorMarkers = $this->containsBridgeErrorMarkers($message) || $this->containsBridgeErrorMarkers($raw);
        $hasImunifyProtection = $this->containsImunifyProtectionMarkers($message) || $this->containsImunifyProtectionMarkers($raw);

        if ($hasImunifyProtection && empty($ticket)) {
            return [self::STATUS_ERROR, 'Bloqueado por seguridad del endpoint (Imunify360)', $ticket, $cdrCode, $cdrDesc];
        }

        if (!empty($ticket)) {
            if ($hasBridgeErrorMarkers) {
                return [self::STATUS_SENT, 'Resumen recibido con ticket; confirmacion SUNAT pendiente', $ticket, $cdrCode, $cdrDesc];
            }

            return [self::STATUS_SENT, 'Resumen enviado – ticket pendiente', $ticket, $cdrCode, $cdrDesc];
        }

        if (in_array($state, ['ACEPTADO', 'ACCEPTED', 'OK'], true)) {
            return [self::STATUS_ACCEPTED, 'Resumen aceptado por SUNAT', $ticket, $cdrCode, $cdrDesc];
        }

        if (in_array($state, ['RECHAZADO', 'REJECTED'], true)) {
            return [self::STATUS_REJECTED, 'Resumen rechazado por SUNAT', $ticket, $cdrCode, $cdrDesc];
        }

        if ($state === 'ERROR') {
            return [self::STATUS_ERROR, 'Error de integracion con puente/SUNAT', $ticket, $cdrCode, $cdrDesc];
        }

        // res=0 semantics differ by bridge generation:
        //  - Legacy bridge (e.g. MundoSoft): res=0 means "enviado/aceptado" (success)
        //  - New bridges: res=0 means error on the bridge side
        // Auto-detect legacy success by the presence of positive keywords in the message.
        if ($resCode === 0) {
            if ($hasBridgeErrorMarkers || $hasImunifyProtection) {
                return [self::STATUS_ERROR, 'Error de integracion con puente/SUNAT', $ticket, $cdrCode, $cdrDesc];
            }
            $msgLower = strtolower($message . ' ' . $raw);
            if (str_contains($msgLower, 'enviado') || str_contains($msgLower, 'ticket')) {
                // Legacy bridge signalling success
                if (!empty($ticket)) {
                    return [self::STATUS_SENT, 'Resumen enviado – ticket pendiente (puente legado)', $ticket, $cdrCode, $cdrDesc];
                }
                return [self::STATUS_ACCEPTED, 'Resumen aceptado por SUNAT (puente legado sincrono)', $ticket, $cdrCode, $cdrDesc];
            }
            return [self::STATUS_ERROR, 'Error de integracion con puente/SUNAT', $ticket, $cdrCode, $cdrDesc];
        }

        if ($resCode === 1 && !$hasBridgeErrorMarkers) {
            return [self::STATUS_ACCEPTED, 'Resumen aceptado por SUNAT', $ticket, $cdrCode, $cdrDesc];
        }

        if ($hasBridgeErrorMarkers) {
            return [self::STATUS_ERROR, 'Error de integracion con puente/SUNAT', $ticket, $cdrCode, $cdrDesc];
        }

        return [self::STATUS_SENT, 'Resumen enviado', $ticket, $cdrCode, $cdrDesc];
    }

    private function buildSummaryRequestDebug(int $companyId, object $summary): ?array
    {
        try {
            $branchId = $summary->branch_id !== null ? (int) $summary->branch_id : null;
            $config = $this->resolveConfig($companyId, $branchId);
            if (($config['raw_base_url'] ?? '') === '') {
                return null;
            }

            $summaryType = (int) $summary->summary_type;
            $bridgeMethod = $summaryType === self::TYPE_DECLARATION ? 'send_resumenDiario' : 'send_anulacion';
            $endpoint = $this->resolveBridgeEndpoint((string) $config['raw_base_url'], $bridgeMethod);
            $payload = $this->buildPayload($companyId, $summary, $config);
            $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($payloadJson)) {
                $payloadJson = '{}';
            }

            return [
                'endpoint' => $endpoint,
                'bridge_method' => $bridgeMethod,
                'method' => 'POST',
                'content_type' => 'application/x-www-form-urlencoded',
                'form_key' => 'datosJSON',
                'payload' => $this->sanitizeSummaryPayloadForDebug($payload),
                'payload_length' => strlen($payloadJson),
                'payload_sha1' => sha1($payloadJson),
            ];
        } catch (\Throwable $e) {
            return [
                'error' => substr($e->getMessage(), 0, 300),
            ];
        }
    }

    public function queryTicketStatus(int $companyId, int $summaryId, ?int $userId = null, ?string $username = null): array
    {
        $summary = DB::table('sales.daily_summaries')
            ->where('id', $summaryId)
            ->where('company_id', $companyId)
            ->first();

        if (!$summary) {
            throw new TaxBridgeException('Daily summary not found', 404);
        }

        $ticket = $this->normalizeSunatTicketValue($summary->sunat_ticket ?? null);
        if ($ticket === null) {
            throw new TaxBridgeException('Daily summary has no SUNAT ticket to consult', 422);
        }

        $summaryType = (int) ($summary->summary_type ?? self::TYPE_DECLARATION);
        $bridgeMethod = trim((string) env('TAX_BRIDGE_SUMMARY_STATUS_METHOD', 'send_statusTicketAsyncUniversal'));
        $branchId = $summary->branch_id !== null ? (int) $summary->branch_id : null;
        $config = $this->resolveConfig($companyId, $branchId);

        $payload = [
            'empresa' => $this->buildCompanyStatusAuthBlock($companyId, $config),
            'cabecera' => [
                'ticket' => $ticket,
                'summary_type' => $summaryType,
                'correlativo_resumen' => (string) ($summary->correlation_number ?? $summary->identifier ?? $summaryId),
            ],
        ];

        $result = $this->taxBridgeService->queryAsyncTicketStatus($companyId, $branchId, $bridgeMethod, $payload);
        $responseData = is_array($result['response'] ?? null) ? $result['response'] : ['raw' => null];

        DB::table('sales.daily_summaries')
            ->where('id', $summaryId)
            ->where('company_id', $companyId)
            ->update([
                'bridge_http_code' => $result['bridge_http_code'] ?? null,
                'raw_response' => json_encode($responseData),
                'sunat_ticket' => $result['sunat_ticket'] ?? $ticket,
                'sunat_cdr_code' => $result['sunat_cdr_code'] ?? null,
                'sunat_cdr_desc' => $result['sunat_cdr_desc'] ?? null,
                'updated_at' => now(),
            ]);

        if (($result['status'] ?? '') === self::STATUS_ACCEPTED || ($result['status'] ?? '') === self::STATUS_REJECTED) {
            DB::table('sales.daily_summaries')
                ->where('id', $summaryId)
                ->where('company_id', $companyId)
                ->update([
                    'status' => $result['status'],
                    'updated_at' => now(),
                ]);

            $this->syncDocumentsAfterSummarySend(
                $companyId,
                $summaryId,
                $summaryType,
                (string) $result['status'],
                $result['sunat_ticket'] ?? $ticket,
                $result['sunat_cdr_code'] ?? null,
                $result['sunat_cdr_desc'] ?? null
            );
        }

        $this->auditService->logDispatch(
            $companyId,
            $branchId,
            $summaryType === self::TYPE_DECLARATION ? 'SUMMARY_RC' : 'SUMMARY_RA',
            null,
            $summaryType === self::TYPE_DECLARATION ? 'RC' : 'RA',
            (string) ($summary->summary_date ?? ''),
            (string) ($summary->identifier ?? $summaryId),
            [
                'bridge_mode' => $config['bridge_mode'] ?? 'PRODUCTION',
                'endpoint_url' => $result['debug']['endpoint'] ?? '',
                'auth_scheme' => $config['auth_scheme'] ?? 'none',
            ],
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            json_encode($responseData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            (int) ($result['bridge_http_code'] ?? 0),
            (float) ($result['response_time_ms'] ?? 0),
            [
                'code' => $result['sunat_cdr_code'] ?? null,
                'ticket' => $result['sunat_ticket'] ?? $ticket,
                'cdr_code' => $result['sunat_cdr_code'] ?? null,
                'message' => $result['sunat_cdr_desc'] ?? null,
            ],
            [
                'sunat_status' => $result['status'] ?? 'PENDING_CONFIRMATION',
                'error_kind' => ($result['status'] ?? '') === self::STATUS_ERROR ? 'HTTP_ERROR' : null,
                'attempt_number' => 1,
                'is_retry' => true,
                'is_manual' => true,
                'user_id' => $userId,
                'username' => $username,
            ]
        );

        return [
            'status' => $result['status'] ?? self::STATUS_SENT,
            'label' => $result['label'] ?? 'Ticket en proceso',
            'bridge_http_code' => $result['bridge_http_code'] ?? null,
            'sunat_ticket' => $result['sunat_ticket'] ?? $ticket,
            'sunat_cdr_code' => $result['sunat_cdr_code'] ?? null,
            'sunat_cdr_desc' => $result['sunat_cdr_desc'] ?? null,
            'sunat_error_code' => $result['sunat_error_code'] ?? null,
            'sunat_error_message' => $result['sunat_error_message'] ?? null,
            'response' => $responseData,
            'debug' => $result['debug'] ?? null,
        ];
    }

    private function normalizeSunatTicketValue($value): ?string
    {
        $ticket = trim((string) ($value ?? ''));
        if ($ticket === '') {
            return null;
        }

        $normalized = strtoupper($ticket);
        if (in_array($normalized, ['NULL', 'NONE', 'N/A', 'NA', '-', 'S/T', 'SIN TICKET'], true)) {
            return null;
        }

        return $ticket;
    }

    private function buildCompanyStatusAuthBlock(int $companyId, array $config): array
    {
        $companyRow = DB::table('core.companies')
            ->where('id', $companyId)
            ->select('tax_id', 'legal_name', 'trade_name')
            ->first();

        $settingsRow = DB::table('core.company_settings')
            ->where('company_id', $companyId)
            ->select('extra_data')
            ->first();

        $extra = [];
        if ($settingsRow && $settingsRow->extra_data) {
            $decoded = json_decode((string) $settingsRow->extra_data, true);
            if (is_array($decoded)) {
                $extra = $decoded;
            }
        }

        $address = trim((string) ($extra['direccion'] ?? ''));

        return [
            'ruc' => (string) ($companyRow->tax_id ?? ''),
            'user' => trim((string) ($config['sol_user'] ?? '')),
            'pass' => trim((string) ($config['sol_pass'] ?? '')),
            'razon_social' => (string) ($companyRow->legal_name ?? ''),
            'nombre_comercial' => (string) ($companyRow->trade_name ?? ''),
            'direccion' => $address,
            'urbanizacion' => trim((string) ($extra['urbanizacion'] ?? '')),
            'ubigeo' => trim((string) ($extra['ubigeo'] ?? '')),
            'departamento' => trim((string) ($extra['departamento'] ?? '')),
            'provincia' => trim((string) ($extra['provincia'] ?? '')),
            'distrito' => trim((string) ($extra['distrito'] ?? '')),
            'codigolocal' => trim((string) ($config['codigolocal'] ?? '')),
            'telefono_fijo' => trim((string) ($companyRow->phone ?? '')),
            'correo' => trim((string) ($companyRow->email ?? '')),
            'envio_pse' => trim((string) ($config['envio_pse'] ?? '')),
        ];
    }

    private function sanitizeSummaryPayloadForDebug(array $payload): array
    {
        if (isset($payload['empresa']['pass'])) {
            $payload['empresa']['pass'] = '********';
        }

        return $payload;
    }

    private function resolveSummaryCustomerDocTypeCode($code): string
    {
        $value = trim((string) ($code ?? ''));
        return $value !== '' ? $value : '0';
    }

    private function extractBridgeMessage($decoded, string $raw): string
    {
        if (is_array($decoded)) {
            foreach (['msg', 'message', 'descripcion', 'description', 'desRespuesta', 'cdr_desc', 'value', 'raw'] as $key) {
                if (array_key_exists($key, $decoded) && is_scalar($decoded[$key])) {
                    return trim((string) $decoded[$key]);
                }
            }
        }

        return trim($raw);
    }

    private function resolveSummaryCdrCode(?string $cdrCode, string $message): ?int
    {
        if ($cdrCode !== null && trim($cdrCode) !== '' && is_numeric($cdrCode)) {
            return (int) $cdrCode;
        }

        if (preg_match('/\[\s*CODE\s*\]\s*=>\s*(\d{4})/i', $message, $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/(?:ERROR|COD(?:IGO)?|RESPUESTA)\D{0,30}(\d{4})\b/i', $message, $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/:\s*(\d{4})\b(?!.*:\s*\d{4}\b)/', $message, $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/^\s*(\d{1,4})\b/', $message, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function containsBridgeErrorMarkers(string $text): bool
    {
        $value = strtoupper(trim($text));
        if ($value === '') {
            return false;
        }

        return preg_match('/\[CODE\]\s*=>|SERVIDOR SUNAT NO RESPONDE|ERROR SOAP|SOAPFAULT|FAULTCODE|EXCEPTION|NO ENCONTRADO|NO HA SIDO COMUNICADO|ERROR EN LA LINEA|XML NO CONTIENE|TASA DEL TRIBUTO FALTANTE|TRIBUTO FALTANTE|BAD REQUEST|HTTP\s*(4\d{2}|5\d{2})|GATEWAY TIME-?OUT|TIME\s*OUT|TIMEOUT|IMUNIFY360|BOT-?PROTECTION|ACCESS DENIED/', $value) === 1;
    }

    private function containsImunifyProtectionMarkers(string $text): bool
    {
        $value = strtoupper(trim($text));
        if ($value === '') {
            return false;
        }

        return preg_match('/IMUNIFY360|BOT-?PROTECTION|ACCESS DENIED/', $value) === 1;
    }

    private function bridgeRequestHeaders(): array
    {
        $userAgent = trim((string) env('TAX_BRIDGE_HTTP_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'));

        return [
            'User-Agent' => $userAgent,
            'Accept' => 'application/json, text/plain, */*',
            'Accept-Language' => 'es-PE,es;q=0.9,en;q=0.8',
            'X-Requested-With' => 'XMLHttpRequest',
            'Cache-Control' => 'no-cache',
            'Pragma' => 'no-cache',
        ];
    }

    private function summarizeSummaryDiagnostic($response, string $cdrCode, string $cdrDesc): array
    {
        $decoded = $response;
        $raw = '';

        if (is_string($response)) {
            $raw = trim($response);
            $parsed = json_decode($raw, true);
            if (is_array($parsed)) {
                $decoded = $parsed;
            }
        } elseif (is_array($response)) {
            $raw = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        }

        $message = trim((string) $cdrDesc);
        if ($message === '') {
            $message = $this->compactSummaryBridgeText($this->extractBridgeMessage($decoded, $raw));
        }

        $resolvedCode = $this->resolveSummaryCdrCode(trim($cdrCode) !== '' ? $cdrCode : null, trim($message . ' ' . $raw));

        return [
            'code' => $resolvedCode !== null ? (string) $resolvedCode : null,
            'message' => $message !== '' ? $message : null,
        ];
    }

    private function compactSummaryBridgeText(string $text): string
    {
        $value = preg_replace('/<br\s*\/?>/i', ' | ', $text) ?? $text;
        $value = strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value, " \t\n\r\0\x0B|");
    }

    private function resolveConfig(int $companyId, ?int $branchId): array
    {
        // Delegate to TaxBridgeService via reflection-free helper: replicate config resolution
        // We call the parent service's internal config via a public proxy method.
        return $this->taxBridgeService->resolvePublicConfig($companyId, $branchId);
    }

    private function resolveBridgeEndpoint(string $rawBaseUrl, string $methodName): string
    {
        $url        = trim($rawBaseUrl);
        $methodName = trim($methodName);

        if ($url === '' || $methodName === '') {
            return '';
        }

        $normalized = rtrim($url, '/');

        if (preg_match('#^(.*?/index\.php/sunat/)([^/?\#]+)(.*)$#i', $normalized, $m) === 1) {
            return $m[1] . $methodName . ($m[3] ?? '');
        }
        if (preg_match('#^(.*?/sunat/)([^/?\#]+)(.*)$#i', $normalized, $m) === 1) {
            return $m[1] . $methodName . ($m[3] ?? '');
        }
        if (preg_match('#/index\.php/sunat$#i', $normalized) === 1) {
            return $normalized . '/' . $methodName;
        }
        if (preg_match('#/sunat$#i', $normalized) === 1) {
            return $normalized . '/' . $methodName;
        }
        if (preg_match('#/index\.php$#i', $normalized) === 1) {
            return $normalized . '/Sunat/' . $methodName;
        }

        return $normalized . '/index.php/Sunat/' . $methodName;
    }

    private function updateDocumentSummaryMetadata(int $companyId, int $documentId, int $summaryType, int $summaryId): void
    {
        $updates = [];
        if ($summaryType === self::TYPE_DECLARATION) {
            $updates = [
                'receipt_send_mode' => 'SUMMARY',
                'sunat_status' => 'PENDING_SUMMARY',
                'sunat_status_label' => 'Pendiente por resumen RC',
                'sunat_summary_id' => $summaryId,
            ];
        } else {
            $updates = [
                'sunat_void_status' => 'PENDING_SUMMARY',
                'sunat_void_label' => 'Pendiente por resumen RA',
                'sunat_void_summary_id' => $summaryId,
            ];
        }

        $this->taxBridgeService->updateCommercialDocumentState($companyId, $documentId, $updates);
    }

    private function clearDocumentSummaryMetadata(int $companyId, int $documentId, int $summaryType, int $summaryId): void
    {
        $row = DB::table('sales.commercial_documents')
            ->where('id', $documentId)
            ->where('company_id', $companyId)
            ->select('metadata', 'status')
            ->first();

        if (!$row) {
            return;
        }

        $metadata = json_decode((string) ($row->metadata ?? '{}'), true);
        $metadata = is_array($metadata) ? $metadata : [];

        if ($summaryType === self::TYPE_DECLARATION) {
            $currentSummaryId = (int) ($metadata['sunat_summary_id'] ?? 0);
            if ($currentSummaryId === $summaryId) {
                $this->taxBridgeService->updateCommercialDocumentState($companyId, $documentId, [
                    'receipt_send_mode' => 'MANUAL',
                    'sunat_status' => 'PENDING_MANUAL',
                    'sunat_status_label' => 'Pendiente manual',
                    'sunat_summary_id' => null,
                    'sunat_ticket' => null,
                    'sunat_cdr_code' => null,
                    'sunat_cdr_desc' => null,
                ]);
            }
        } else {
            $currentSummaryId = (int) ($metadata['sunat_void_summary_id'] ?? 0);
            if ($currentSummaryId === $summaryId) {
                $this->taxBridgeService->updateCommercialDocumentState(
                    $companyId,
                    $documentId,
                    [
                        'sunat_void_status' => null,
                        'sunat_void_label' => null,
                        'sunat_void_summary_id' => null,
                        'sunat_void_ticket' => null,
                    ],
                    strtoupper((string) ($row->status ?? '')) === 'VOID' ? 'ISSUED' : null
                );
            }
        }
    }

    private function syncDocumentsAfterSummarySend(
        int $companyId,
        int $summaryId,
        int $summaryType,
        string $summaryStatus,
        ?string $ticket,
        ?string $cdrCode,
        ?string $cdrDesc
    ): void {
        $documentIds = DB::table('sales.daily_summary_items')
            ->where('summary_id', $summaryId)
            ->pluck('document_id')
            ->all();

        foreach ($documentIds as $docId) {
            $row = DB::table('sales.commercial_documents')
                ->where('id', (int) $docId)
                ->where('company_id', $companyId)
                ->select('metadata')
                ->first();

            if (!$row) {
                continue;
            }

            $metadata = json_decode((string) ($row->metadata ?? '{}'), true);
            $metadata = is_array($metadata) ? $metadata : [];

            if ($summaryType === self::TYPE_DECLARATION) {
                if ($summaryStatus === self::STATUS_ACCEPTED) {
                    $metadata['sunat_status'] = 'ACCEPTED';
                    $metadata['sunat_status_label'] = 'Aceptado por resumen RC';
                } elseif ($summaryStatus === self::STATUS_REJECTED) {
                    $metadata['sunat_status'] = 'REJECTED';
                    $metadata['sunat_status_label'] = 'Rechazado por resumen RC';
                } else {
                    $metadata['sunat_status'] = 'SENT_BY_SUMMARY';
                    $metadata['sunat_status_label'] = 'Enviado por resumen RC';
                }

                $metadata['sunat_ticket'] = $ticket;
                $metadata['sunat_summary_id'] = $summaryId;
                if ($cdrCode !== null) {
                    $metadata['sunat_cdr_code'] = $cdrCode;
                }
                if ($cdrDesc !== null) {
                    $metadata['sunat_cdr_desc'] = $cdrDesc;
                }

                if ($summaryStatus === self::STATUS_ACCEPTED) {
                    $this->taxBridgeService->settleInventoryForAcceptedDocumentIfNeeded($companyId, (int) $docId);
                }
            } else {
                if ($summaryStatus === self::STATUS_ACCEPTED) {
                    $metadata['sunat_void_status'] = 'ACCEPTED';
                    $metadata['sunat_void_label'] = 'Aceptado por resumen RA';
                } elseif ($summaryStatus === self::STATUS_REJECTED) {
                    $metadata['sunat_void_status'] = 'REJECTED';
                    $metadata['sunat_void_label'] = 'Rechazado por resumen RA';
                } else {
                    $metadata['sunat_void_status'] = 'SENT_BY_SUMMARY';
                    $metadata['sunat_void_label'] = 'Enviado por resumen RA';
                }

                $metadata['sunat_void_ticket'] = $ticket;
                $metadata['sunat_void_summary_id'] = $summaryId;
            }

            $this->taxBridgeService->updateCommercialDocumentState(
                $companyId,
                (int) $docId,
                $metadata,
                $summaryType === self::TYPE_CANCELLATION && $summaryStatus === self::STATUS_ACCEPTED ? 'VOID' : null
            );

            if ($summaryType === self::TYPE_CANCELLATION && $summaryStatus === self::STATUS_ACCEPTED) {
                $this->taxBridgeService->reverseInventoryForVoidedDocumentIfNeeded($companyId, (int) $docId);
            }
        }
    }
}
