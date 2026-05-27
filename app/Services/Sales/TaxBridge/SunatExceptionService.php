<?php

namespace App\Services\Sales\TaxBridge;

use Illuminate\Support\Facades\DB;

class SunatExceptionService
{
    private const FINAL_SUNAT_STATUSES = [
        'ACCEPTED',
        'SENT_BY_SUMMARY',
    ];

    public function __construct(private TaxBridgeService $taxBridgeService)
    {
    }

    public function list(
        int $companyId,
        ?int $branchId,
        ?string $status,
        int $minAgeHours,
        int $minAttempts,
        bool $onlyManualNeeded,
        int $page,
        int $perPage
    ): array {
        $query = DB::table('sales.commercial_documents as d')
            ->leftJoin('sales.customers as c', 'c.id', '=', 'd.customer_id')
            ->where('d.company_id', $companyId)
            ->where('d.status', 'ISSUED')
            ->whereNotIn(DB::raw("UPPER(COALESCE(d.metadata->>'sunat_status',''))"), self::FINAL_SUNAT_STATUSES)
            ->select([
                'd.id',
                'd.branch_id',
                'd.document_kind',
                'd.series',
                'd.number',
                'd.issue_at',
                'd.status as document_status',
                'd.updated_at',
                'd.metadata',
                DB::raw("COALESCE(NULLIF(c.legal_name, ''), NULLIF(c.trade_name, ''), NULLIF(TRIM(COALESCE(c.first_name, '') || ' ' || COALESCE(c.last_name, '')), ''), 'Sin cliente') as customer_name"),
                DB::raw("UPPER(COALESCE(d.metadata->>'sunat_status','')) as sunat_status"),
                DB::raw("COALESCE(NULLIF(d.metadata->>'sunat_status_label',''), NULLIF(d.metadata->>'sunat_bridge_note',''), 'Pendiente SUNAT') as sunat_label"),
                DB::raw("COALESCE((d.metadata->>'sunat_reconcile_attempts')::int, 0) as reconcile_attempts"),
                DB::raw("(SELECT COUNT(*)::int FROM sales.tax_bridge_audit_logs l WHERE l.company_id = d.company_id AND l.document_id = d.id) as bridge_attempts"),
                DB::raw("(SELECT UPPER(COALESCE(l.sunat_status, '')) FROM sales.tax_bridge_audit_logs l WHERE l.company_id = d.company_id AND l.document_id = d.id ORDER BY COALESCE(l.sent_at, l.created_at) DESC, l.id DESC LIMIT 1) as bridge_last_status"),
                DB::raw("(SELECT COALESCE(l.sent_at, l.created_at) FROM sales.tax_bridge_audit_logs l WHERE l.company_id = d.company_id AND l.document_id = d.id ORDER BY COALESCE(l.sent_at, l.created_at) DESC, l.id DESC LIMIT 1) as bridge_last_at"),
                DB::raw("COALESCE((d.metadata->>'inventory_pending_sunat')::boolean, false) as inventory_pending_sunat"),
                DB::raw("COALESCE((d.metadata->>'inventory_sunat_settled')::boolean, false) as inventory_sunat_settled"),
                DB::raw("COALESCE((d.metadata->>'sunat_needs_manual_confirmation')::boolean, false) as needs_manual_confirmation"),
                DB::raw("GREATEST(0, FLOOR(EXTRACT(EPOCH FROM (NOW() - d.updated_at)) / 3600))::int as pending_hours"),
            ]);

            $this->applyTributaryDocumentKindFilter($query, 'd.document_kind');

        if ($branchId !== null) {
            $query->where('d.branch_id', $branchId);
        }

        if ($status !== null && $status !== '') {
            $normalizedStatus = strtoupper(trim($status));

            if ($normalizedStatus === 'PENDING_CONFIRMATION') {
                $query->whereIn(DB::raw("UPPER(COALESCE(d.metadata->>'sunat_status',''))"), [
                    '',
                    'PENDING_CONFIRMATION',
                    'PENDING',
                    'NOT_SENT',
                    'PENDING_SUMMARY',
                    'PENDING_MANUAL',
                ]);
            } else {
                $query->where(DB::raw("UPPER(COALESCE(d.metadata->>'sunat_status',''))"), $normalizedStatus);
            }
        }

        if ($minAgeHours > 0) {
            $query->whereRaw('EXTRACT(EPOCH FROM (NOW() - d.updated_at)) >= ?', [$minAgeHours * 3600]);
        }

        if ($minAttempts > 0) {
            $query->whereRaw("GREATEST(COALESCE((d.metadata->>'sunat_reconcile_attempts')::int, 0), (SELECT COUNT(*)::int FROM sales.tax_bridge_audit_logs l WHERE l.company_id = d.company_id AND l.document_id = d.id)) >= ?", [$minAttempts]);
        }

        if ($onlyManualNeeded) {
            $query->whereRaw("COALESCE((d.metadata->>'sunat_needs_manual_confirmation')::boolean, false) = true");
        }

        $total = (clone $query)->count();
        $rows = $query
            ->orderByDesc('pending_hours')
            ->orderByDesc('d.updated_at')
            ->forPage($page, $perPage)
            ->get();

        $data = [];
        foreach ($rows as $row) {
            $metadata = json_decode((string) ($row->metadata ?? '{}'), true);
            $metadata = is_array($metadata) ? $metadata : [];
            $diagnostic = $this->taxBridgeService->summarizeBridgeDiagnostic($metadata['sunat_bridge_response'] ?? null);
            $reconcileAttempts = (int) $row->reconcile_attempts;
            $bridgeAttempts = (int) ($row->bridge_attempts ?? 0);
            $effectiveAttempts = max($reconcileAttempts, $bridgeAttempts);
            $bridgeLastStatus = strtoupper(trim((string) ($row->bridge_last_status ?? '')));
            $effectiveSunatStatus = strtoupper(trim((string) $row->sunat_status));
            $effectiveSunatLabel = (string) $row->sunat_label;

            if ($bridgeLastStatus !== '' && $this->shouldPrioritizeBridgeStatus((string) $row->updated_at, $row->bridge_last_at ?? null)) {
                $effectiveSunatStatus = $bridgeLastStatus;
                $effectiveSunatLabel = $this->mapSunatStatusLabel($bridgeLastStatus, (string) $row->sunat_label);
            }

            $data[] = [
                'id' => (int) $row->id,
                'branch_id' => $row->branch_id !== null ? (int) $row->branch_id : null,
                'document_kind' => (string) $row->document_kind,
                'series' => (string) $row->series,
                'number' => (int) $row->number,
                'issue_at' => (string) $row->issue_at,
                'document_status' => (string) $row->document_status,
                'customer_name' => (string) $row->customer_name,
                'sunat_status' => $effectiveSunatStatus,
                'sunat_label' => $effectiveSunatLabel,
                'pending_hours' => (int) $row->pending_hours,
                'reconcile_attempts' => $reconcileAttempts,
                'bridge_attempts' => $bridgeAttempts,
                'effective_attempts' => $effectiveAttempts,
                'bridge_last_status' => $bridgeLastStatus !== '' ? $bridgeLastStatus : null,
                'bridge_last_at' => $row->bridge_last_at !== null ? (string) $row->bridge_last_at : null,
                'needs_manual_confirmation' => (bool) $row->needs_manual_confirmation,
                'inventory_pending_sunat' => (bool) $row->inventory_pending_sunat,
                'inventory_sunat_settled' => (bool) $row->inventory_sunat_settled,
                'inventory_mismatch' => $this->isInventoryMismatch($effectiveSunatStatus, (bool) $row->inventory_sunat_settled),
                'sunat_reconcile_next_at' => $metadata['sunat_reconcile_next_at'] ?? null,
                'sunat_bridge_http_code' => $metadata['sunat_bridge_http_code'] ?? null,
                'sunat_bridge_note' => $metadata['sunat_bridge_note'] ?? null,
                'sunat_error_code' => $diagnostic['code'] ?? null,
                'sunat_error_message' => $diagnostic['message'] ?? null,
            ];
        }

        return [
            'data' => $data,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / max($perPage, 1))),
            ],
        ];
    }

    public function auditPendingVsInventory(
        int $companyId,
        ?int $branchId,
        ?string $dateFrom,
        ?string $dateTo,
        int $limit
    ): array {
        $query = DB::table('sales.commercial_documents as d')
            ->where('d.company_id', $companyId)
            ->where('d.status', 'ISSUED')
            ->select([
                'd.id',
                'd.branch_id',
                'd.document_kind',
                'd.series',
                'd.number',
                'd.issue_at',
                'd.updated_at',
                'd.metadata',
                DB::raw("UPPER(COALESCE(d.metadata->>'sunat_status','')) as sunat_status"),
                DB::raw("COALESCE((d.metadata->>'inventory_sunat_settled')::boolean, false) as inventory_sunat_settled"),
                DB::raw("COALESCE((d.metadata->>'inventory_pending_sunat')::boolean, false) as inventory_pending_sunat"),
            ]);

            $this->applyTributaryDocumentKindFilter($query, 'd.document_kind');

        if ($branchId !== null) {
            $query->where('d.branch_id', $branchId);
        }

        if ($dateFrom !== null && $dateFrom !== '') {
            $query->whereDate('d.issue_at', '>=', $dateFrom);
        }

        if ($dateTo !== null && $dateTo !== '') {
            $query->whereDate('d.issue_at', '<=', $dateTo);
        }

        $rows = $query
            ->orderByDesc('d.issue_at')
            ->limit(max(1, min($limit, 500)))
            ->get();

        $summary = [
            'total_issued' => 0,
            'pending_sunat' => 0,
            'inventory_settled' => 0,
            'mismatch_count' => 0,
        ];

        $mismatches = [];

        foreach ($rows as $row) {
            $summary['total_issued']++;

            $sunatStatus = strtoupper((string) ($row->sunat_status ?? ''));
            $inventorySettled = (bool) $row->inventory_sunat_settled;
            $inventoryPending = (bool) $row->inventory_pending_sunat;

            if ($sunatStatus !== 'ACCEPTED') {
                $summary['pending_sunat']++;
            }

            if ($inventorySettled) {
                $summary['inventory_settled']++;
            }

            $isMismatch = $this->isInventoryMismatch($sunatStatus, $inventorySettled);
            if ($isMismatch) {
                $summary['mismatch_count']++;
                $mismatches[] = [
                    'id' => (int) $row->id,
                    'branch_id' => $row->branch_id !== null ? (int) $row->branch_id : null,
                    'document_kind' => (string) $row->document_kind,
                    'series' => (string) $row->series,
                    'number' => (int) $row->number,
                    'issue_at' => (string) $row->issue_at,
                    'updated_at' => (string) $row->updated_at,
                    'sunat_status' => $sunatStatus,
                    'inventory_sunat_settled' => $inventorySettled,
                    'inventory_pending_sunat' => $inventoryPending,
                    'mismatch_reason' => $sunatStatus === 'ACCEPTED'
                        ? 'SUNAT aceptado pero inventario no consolidado'
                        : 'Inventario consolidado sin aceptacion SUNAT',
                ];
            }
        }

        return [
            'summary' => $summary,
            'data' => $mismatches,
        ];
    }

    private function applyTributaryDocumentKindFilter($query, string $column): void
    {
        $query->where(function ($nested) use ($column) {
            $nested->whereRaw("UPPER($column) = 'INVOICE'")
                ->orWhereRaw("UPPER($column) = 'RECEIPT'")
                ->orWhereRaw("UPPER($column) = 'CREDIT_NOTE'")
                ->orWhereRaw("UPPER($column) = 'DEBIT_NOTE'")
                ->orWhereRaw("UPPER($column) LIKE 'CREDIT_NOTE_%'")
                ->orWhereRaw("UPPER($column) LIKE 'DEBIT_NOTE_%'");
        });
    }

    public function manualConfirm(
        int $companyId,
        int $documentId,
        int $actorId,
        string $resolution,
        string $evidenceType,
        ?string $evidenceRef,
        ?string $evidenceNote
    ): array {
        return DB::transaction(function () use ($companyId, $documentId, $actorId, $resolution, $evidenceType, $evidenceRef, $evidenceNote) {
            $document = DB::table('sales.commercial_documents')
                ->where('id', $documentId)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->first();

            if (!$document) {
                throw new TaxBridgeException('Documento no encontrado', 404);
            }

            $metadata = json_decode((string) ($document->metadata ?? '{}'), true);
            $metadata = is_array($metadata) ? $metadata : [];
            $previousStatus = strtoupper((string) ($metadata['sunat_status'] ?? 'PENDING_CONFIRMATION'));

            $manualResult = $this->taxBridgeService->manualConfirmWithEvidence(
                $companyId,
                $document->branch_id !== null ? (int) $document->branch_id : null,
                $documentId,
                $resolution,
                $actorId,
                [
                    'type' => $evidenceType,
                    'reference' => $evidenceRef,
                    'note' => $evidenceNote,
                ]
            );

            if (DB::table('information_schema.tables')
                ->where('table_schema', 'sales')
                ->where('table_name', 'sunat_exception_actions')
                ->exists()) {
                DB::table('sales.sunat_exception_actions')->insert([
                    'company_id' => $companyId,
                    'document_id' => $documentId,
                    'action_type' => 'MANUAL_CONFIRM',
                    'previous_status' => $previousStatus,
                    'new_status' => (string) $manualResult['sunat_status'],
                    'evidence_type' => $evidenceType,
                    'evidence_ref' => $evidenceRef,
                    'evidence_note' => $evidenceNote,
                    'performed_by' => $actorId,
                    'performed_at' => now(),
                    'metadata' => json_encode([
                        'sunat_status_label' => $manualResult['sunat_status_label'] ?? null,
                    ]),
                    'created_at' => now(),
                ]);
            }

            return [
                'message' => 'Confirmacion manual registrada',
                'document_id' => $documentId,
                'sunat_status' => $manualResult['sunat_status'],
                'sunat_status_label' => $manualResult['sunat_status_label'],
                'inventory_sunat_settled' => $manualResult['inventory_sunat_settled'] ?? null,
            ];
        });
    }

    private function isInventoryMismatch(string $sunatStatus, bool $inventorySettled): bool
    {
        return ($sunatStatus === 'ACCEPTED' && !$inventorySettled)
            || ($sunatStatus !== 'ACCEPTED' && $inventorySettled);
    }

    private function mapSunatStatusLabel(string $status, string $fallback): string
    {
        return match (strtoupper(trim($status))) {
            'ACCEPTED' => 'Aceptado',
            'REJECTED' => 'Rechazado',
            'PENDING_CONFIRMATION' => 'Pendiente confirmacion SUNAT',
            'SENDING' => 'Enviando',
            'SENT' => 'Enviado',
            'HTTP_ERROR' => 'Error HTTP',
            'NETWORK_ERROR' => 'Error de red',
            default => $fallback,
        };
    }

    private function shouldPrioritizeBridgeStatus(string $documentUpdatedAt, $bridgeLastAt): bool
    {
        if ($bridgeLastAt === null || trim((string) $bridgeLastAt) === '') {
            return false;
        }

        try {
            $docAt = \Carbon\Carbon::parse($documentUpdatedAt);
            $bridgeAt = \Carbon\Carbon::parse((string) $bridgeLastAt);

            return $bridgeAt->greaterThanOrEqualTo($docAt);
        } catch (\Throwable $e) {
            // Fallback: if parsing fails, prefer bridge only when metadata status is empty.
            return trim($documentUpdatedAt) === '';
        }
    }
}
