<?php

namespace App\Services\Sales\Documents;

use App\Application\Commands\Sales\VoidCommercialDocumentCommand;
use App\Domain\Sales\Entities\CommercialDocumentEntity;
use App\Domain\Sales\Repositories\CommercialDocumentRepositoryInterface;
use App\Services\Sales\TaxBridge\DailySummaryService;
use DomainException;
use Illuminate\Support\Facades\DB;

class SalesDocumentVoidService
{
    public function __construct(
        private SalesDocumentSupportService $support,
        private SalesStockProjectionService $stockProjectionService,
        private DailySummaryService $dailySummaryService,
        private CommercialDocumentRepositoryInterface $documentRepository
    )
    {
    }

    public function void(object $authUser, int $companyId, int $documentId, array $payload): array
    {
        return $this->voidFromCommand(
            VoidCommercialDocumentCommand::fromInput($authUser, $companyId, $documentId, $payload)
        );
    }

    public function voidFromCommand(VoidCommercialDocumentCommand $command): array
    {
        $authUser = $command->authUser;
        $companyId = $command->companyId;
        $documentId = $command->documentId;
        $payload = $command->payload;

        $this->stockProjectionService->reset();

        $roleCode = strtoupper(trim((string) ($authUser->role_code ?? '')));
        $roleProfile = strtoupper(trim((string) ($authUser->role_profile ?? '')));

        if ($roleCode === '' && $roleProfile === '') {
            $roleContext = $this->support->resolveAuthRoleContext((int) $authUser->id, $companyId);
            $roleCode = strtoupper(trim((string) ($roleContext['role_code'] ?? '')));
            $roleProfile = strtoupper(trim((string) ($roleContext['role_profile'] ?? '')));
        }

        $document = $this->documentRepository->findById($documentId, $companyId);

        if (!$document) {
            throw new SalesDocumentException('Documento no encontrado', 404);
        }

        $documentEntity = CommercialDocumentEntity::fromPersistence($document);

        try {
            $documentEntity->assertCanVoid();
        } catch (DomainException $e) {
            throw new SalesDocumentException($e->getMessage(), 409);
        }

        if ($this->support->hasActiveChildConversions($companyId, $documentId)) {
            throw new SalesDocumentException('No se puede anular: tiene documentos convertidos activos.', 422);
        }

        $featureBranchId = $document->branch_id !== null ? (int) $document->branch_id : null;
        if (!$this->support->isCommerceFeatureEnabledForContextWithDefault($companyId, $featureBranchId, 'SALES_ALLOW_DOCUMENT_VOID', true)) {
            throw new SalesDocumentException('La anulacion de documentos esta deshabilitada para este contexto.', 403);
        }

        $allowInventoryReverseOnVoid = $this->support->isCommerceFeatureEnabledForContextWithDefault(
            $companyId,
            $featureBranchId,
            'SALES_VOID_REVERSE_STOCK',
            true
        );

        if (!$this->canActorVoidDocuments($companyId, $featureBranchId, $roleProfile, $roleCode)) {
            throw new SalesDocumentException('La anulacion esta bloqueada para su perfil en esta sucursal.', 403);
        }

        try {
            return DB::transaction(function () use ($payload, $authUser, $companyId, $document, $documentId, $allowInventoryReverseOnVoid, $documentEntity) {
                $metadata = $documentEntity->metadata();
                $alreadyReverted = !empty($metadata['inventory_void_reverted']);
                $stockWasAffected = $documentEntity->shouldAffectStockOnCurrentState();

                if ($allowInventoryReverseOnVoid && $stockWasAffected && !$alreadyReverted) {
                    $this->reverseInventoryLedgerForDocument(
                        $companyId,
                        $documentId,
                        isset($payload['void_at']) ? (string) $payload['void_at'] : null,
                        (int) $authUser->id
                    );
                }

                $metadata = $documentEntity->buildVoidMetadata(
                    $payload,
                    (int) $authUser->id,
                    $allowInventoryReverseOnVoid,
                    $allowInventoryReverseOnVoid && $stockWasAffected
                );

                $dailySummaryId = null;
                $isReceiptGoingToRa = false;

                if (strtoupper((string) ($document->document_kind ?? '')) === 'RECEIPT') {
                    $documentStatus = strtoupper((string) ($document->status ?? ''));
                    $sunatStatus = strtoupper(trim((string) ($metadata['sunat_status'] ?? '')));
                    $shouldRouteReceiptToCancellationSummary = in_array($documentStatus, ['VOID', 'VOIDED'], true)
                        || ($documentStatus === 'ISSUED' && in_array($sunatStatus, ['ACCEPTED', 'SENT_BY_SUMMARY'], true));

                    if ($shouldRouteReceiptToCancellationSummary) {
                    $voidDate = isset($payload['void_at']) && trim((string) $payload['void_at']) !== ''
                        ? date('Y-m-d', strtotime((string) $payload['void_at']))
                        : now()->toDateString();

                    $summary = $this->dailySummaryService->appendDocumentToOpenSummary(
                        $companyId,
                        DailySummaryService::TYPE_CANCELLATION,
                        $documentId,
                        (int) $authUser->id,
                        $document->branch_id !== null ? (int) $document->branch_id : null,
                        $voidDate
                    );

                    $dailySummaryId = (int) ($summary['id'] ?? 0) ?: null;
                    $isReceiptGoingToRa = true;

                    // Merge the freshest metadata written by the summary service
                    // and then re-apply void metadata fields from this operation.
                    $currentRow = DB::table('sales.commercial_documents')
                        ->where('id', $documentId)
                        ->where('company_id', $companyId)
                        ->select('metadata')
                        ->first();

                    $currentMeta = json_decode((string) ($currentRow->metadata ?? '{}'), true);
                    $currentMeta = is_array($currentMeta) ? $currentMeta : [];

                    $metadata = array_merge($currentMeta, $metadata);
                    $metadata['sunat_void_status'] = 'PENDING_SUMMARY';
                    $metadata['sunat_void_label'] = 'Pendiente por resumen RA';
                    if ($dailySummaryId !== null) {
                        $metadata['sunat_void_summary_id'] = $dailySummaryId;
                    }
                    } else {
                        // Pre-SUNAT-final receipts are voided internally and should not be forced into RA.
                        unset($metadata['sunat_void_status'], $metadata['sunat_void_label'], $metadata['sunat_void_summary_id']);
                    }
                }

                // Actualizar documento: mantener ISSUED para boletas en RA, VOID para otros
                $this->documentRepository->update($documentId, $companyId, [
                    'status' => $isReceiptGoingToRa ? 'ISSUED' : 'VOID',
                    'notes' => isset($payload['notes']) ? (string) $payload['notes'] : $documentEntity->notes(),
                    'metadata' => json_encode($metadata),
                    'updated_by' => $authUser->id,
                    'updated_at' => now(),
                ]);

                if (!$isReceiptGoingToRa) {
                    $this->releaseRestaurantTableOnVoid(
                        $companyId,
                        $document->branch_id !== null ? (int) $document->branch_id : null,
                        (string) ($document->document_kind ?? ''),
                        $metadata
                    );
                }

                return [
                    'id' => $documentId,
                    'status' => $isReceiptGoingToRa ? 'ISSUED' : 'VOID',
                    'inventory_reverted' => $allowInventoryReverseOnVoid && $stockWasAffected,
                    'daily_summary_id' => $dailySummaryId,
                ];
            });
        } catch (\RuntimeException $e) {
            throw new SalesDocumentException($e->getMessage(), 422);
        }
    }

    private function reverseInventoryLedgerForDocument(int $companyId, int $documentId, ?string $voidAt, int $userId): void
    {
        $settings = $this->inventorySettingsForCompany($companyId);
        $movedAt = $voidAt ?: now();

        $rows = DB::table('inventory.inventory_ledger')
            ->where('company_id', $companyId)
            ->where('ref_type', 'COMMERCIAL_DOCUMENT')
            ->where('ref_id', $documentId)
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $originalType = strtoupper((string) $row->movement_type);
            if (!in_array($originalType, ['IN', 'OUT'], true)) {
                continue;
            }

            $reverseType = $originalType === 'IN' ? 'OUT' : 'IN';
            $qty = round((float) ($row->quantity ?? 0), 8);
            if ($qty <= 0) {
                continue;
            }

            $delta = $reverseType === 'IN' ? $qty : -$qty;
            $this->stockProjectionService->applyCurrentStockDelta($companyId, (int) $row->warehouse_id, (int) $row->product_id, $delta, (bool) $settings['allow_negative_stock']);

            if ($row->lot_id !== null) {
                $this->stockProjectionService->applyLotStockDelta($companyId, (int) $row->warehouse_id, (int) $row->product_id, (int) $row->lot_id, $delta, (bool) $settings['allow_negative_stock']);
            }

            DB::table('inventory.inventory_ledger')->insert([
                'company_id' => $companyId,
                'warehouse_id' => (int) $row->warehouse_id,
                'product_id' => (int) $row->product_id,
                'lot_id' => $row->lot_id !== null ? (int) $row->lot_id : null,
                'movement_type' => $reverseType,
                'quantity' => $qty,
                'unit_cost' => (float) ($row->unit_cost ?? 0),
                'ref_type' => 'COMMERCIAL_DOCUMENT_VOID',
                'ref_id' => $documentId,
                'notes' => 'Reversa por anulacion de doc comercial #' . $documentId,
                'moved_at' => $movedAt,
                'created_by' => $userId,
            ]);
        }
    }

    private function inventorySettingsForCompany(int $companyId): array
    {
        $row = DB::table('inventory.inventory_settings')->where('company_id', $companyId)->first();

        if (!$row) {
            return [
                'allow_negative_stock' => false,
            ];
        }

        return [
            'allow_negative_stock' => (bool) $row->allow_negative_stock,
        ];
    }

    private function releaseRestaurantTableOnVoid(int $companyId, ?int $branchId, string $documentKind, array $metadata): void
    {
        if (strtoupper(trim($documentKind)) !== 'SALES_ORDER') {
            return;
        }

        $tableLabel = trim((string) ($metadata['table_label'] ?? ''));
        if ($tableLabel === '' || !$this->restaurantTablesStorageExists()) {
            return;
        }

        $table = DB::table('restaurant.tables')
            ->where('company_id', $companyId)
            ->when($branchId !== null, function ($query) use ($branchId) {
                $query->where('branch_id', $branchId);
            })
            ->where(function ($query) use ($tableLabel) {
                $query->whereRaw('UPPER(name) = ?', [mb_strtoupper($tableLabel)])
                    ->orWhereRaw('UPPER(code) = ?', [mb_strtoupper($tableLabel)]);
            })
            ->first(['id', 'status']);

        if (!$table || strtoupper((string) $table->status) === 'DISABLED') {
            return;
        }

        DB::table('restaurant.tables')
            ->where('id', (int) $table->id)
            ->where('company_id', $companyId)
            ->update([
                'status' => 'AVAILABLE',
                'updated_at' => now(),
            ]);
    }

    private function restaurantTablesStorageExists(): bool
    {
        return DB::table('information_schema.tables')
            ->where('table_schema', 'restaurant')
            ->where('table_name', 'tables')
            ->exists();
    }

    private function canActorVoidDocuments(int $companyId, ?int $branchId, string $roleProfile, string $roleCode): bool
    {
        if ($this->support->isAdminActor($roleCode)) {
            return $this->support->isCommerceFeatureEnabledForContextWithDefault($companyId, $branchId, 'SALES_ALLOW_VOID_FOR_ADMIN', true);
        }

        if ($this->support->isCashierActor($roleProfile, $roleCode)) {
            return $this->support->isCommerceFeatureEnabledForContextWithDefault($companyId, $branchId, 'SALES_ALLOW_VOID_FOR_CASHIER', true);
        }

        if ($this->support->isSellerActor($roleProfile, $roleCode)) {
            return $this->support->isCommerceFeatureEnabledForContextWithDefault($companyId, $branchId, 'SALES_ALLOW_VOID_FOR_SELLER', true);
        }

        return false;
    }
}
