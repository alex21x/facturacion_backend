<?php

namespace App\Infrastructure\Repositories\Sales\TaxBridge;

use App\Infrastructure\Repositories\Sales\SunatExceptionRepository;

class SunatExceptionService
{
    public function __construct(
        private TaxBridgeService $taxBridgeService,
        private SunatExceptionRepository $sunatExceptionRepository
    )
    {
    }

    public function list(
        int $companyId,
        ?int $branchId,
        ?string $status,
        ?string $documentKind,
        ?string $document,
        ?string $series,
        ?string $number,
        int $minAgeHours,
        int $minAttempts,
        bool $onlyManualNeeded,
        int $page,
        int $perPage
    ): array {
        $result = $this->sunatExceptionRepository->listExceptions(
            $companyId,
            $branchId,
            $status,
            $documentKind,
            $document,
            $series,
            $number,
            $minAgeHours,
            $minAttempts,
            $onlyManualNeeded,
            $page,
            $perPage
        );

        $total = (int) $result['total'];
        $rows = $result['rows'];

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
                'document_kind_label' => $this->mapDocumentKindLabel((string) $row->document_kind),
                'series' => (string) $row->series,
                'number' => (int) $row->number,
                'issue_at' => (string) $row->issue_at,
                'document_status' => (string) $row->document_status,
                'total' => isset($row->total) ? (string) $row->total : null,
                'issuer_ruc' => isset($row->issuer_ruc) ? trim((string) $row->issuer_ruc) : null,
                'customer_name' => (string) $row->customer_name,
                'customer_doc_number' => isset($row->customer_doc_number) ? trim((string) $row->customer_doc_number) : null,
                'customer_doc_type_code' => isset($row->customer_doc_type_code) ? trim((string) $row->customer_doc_type_code) : null,
                'customer_type_label' => $this->mapCustomerTypeLabel(isset($row->customer_doc_type_code) ? (string) $row->customer_doc_type_code : null),
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
        $rows = $this->sunatExceptionRepository->listAuditRows(
            $companyId,
            $branchId,
            $dateFrom,
            $dateTo,
            $limit
        );

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

    public function manualConfirm(
        int $companyId,
        int $documentId,
        int $actorId,
        string $resolution,
        string $evidenceType,
        ?string $evidenceRef,
        ?string $evidenceNote
    ): array {
        return $this->sunatExceptionRepository->runInTransaction(function () use ($companyId, $documentId, $actorId, $resolution, $evidenceType, $evidenceRef, $evidenceNote) {
            $document = $this->sunatExceptionRepository->lockCommercialDocument($companyId, $documentId);

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

            if ($this->sunatExceptionRepository->sunatExceptionActionsTableExists()) {
                $this->sunatExceptionRepository->insertSunatExceptionAction([
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

    private function mapCustomerTypeLabel(?string $customerDocTypeCode): string
    {
        $code = trim((string) ($customerDocTypeCode ?? ''));
        if ($code === '') {
            return '-';
        }

        return match ($code) {
            '6' => 'JURIDICA',
            '1', '4', '7', '0', 'A' => 'NATURAL',
            default => 'NATURAL',
        };
    }

    private function mapDocumentKindLabel(string $documentKind): string
    {
        $normalized = strtoupper(trim($documentKind));

        return match (true) {
            $normalized === 'INVOICE' => 'FACTURA',
            $normalized === 'RECEIPT' => 'BOLETA',
            str_starts_with($normalized, 'CREDIT_NOTE') => 'NOTA DE CREDITO',
            str_starts_with($normalized, 'DEBIT_NOTE') => 'NOTA DE DEBITO',
            default => $normalized !== '' ? $normalized : '-',
        };
    }
}
