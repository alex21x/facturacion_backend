<?php

namespace App\Application\UseCases\Sales;

use App\Services\Sales\SalesBusinessRuleService;
use App\Services\Sales\SalesDocumentValidationService;
use App\Services\Sales\SalesLookupService;
use App\Services\Sales\Documents\SalesDocumentException;

class PrepareCreateCommercialDocumentUseCase
{
    private const FEATURE_ALLOW_RECEIPT_RUC = 'SALES_ALLOW_RECEIPT_WITH_RUC';

    public function __construct(
        private SalesBusinessRuleService $salesBusinessRuleService,
        private SalesDocumentValidationService $salesDocumentValidationService,
        private SalesLookupService $salesLookupService
    ) {
    }

    public function execute(
        object $authUser,
        array $payload,
        int $companyId,
        bool $workshopMultiVehicleEnabled
    ): array {
        $documentKindId = array_key_exists('document_kind_id', $payload) ? (int) $payload['document_kind_id'] : 0;
        if ($documentKindId > 0) {
            $documentKindCode = $this->resolveDocumentKindCodeById($documentKindId);
            if ($documentKindCode === null) {
                throw new SalesDocumentException('document_kind_id invalido');
            }

            $payload['document_kind'] = $documentKindCode;
            $payload['document_kind_id'] = $documentKindId;
        }

        $branchId = array_key_exists('branch_id', $payload) ? $payload['branch_id'] : $authUser->branch_id;
        $warehouseId = $payload['warehouse_id'] ?? null;
        $cashRegisterId = $payload['cash_register_id'] ?? null;

        if ($warehouseId === null) {
            $warehouseId = $authUser->preferred_warehouse_id ?? null;
        }
        if ($warehouseId === null) {
            $fallbackWarehouse = $this->salesDocumentValidationService->firstActiveWarehouseId($companyId);
            if ($fallbackWarehouse !== null) {
                $warehouseId = $fallbackWarehouse;
            }
        }

        if ($branchId !== null) {
            $branchExists = $this->salesDocumentValidationService->branchExists($companyId, (int) $branchId);

            if (!$branchExists) {
                throw new SalesDocumentException('Invalid branch scope');
            }
        }

        if ($warehouseId !== null) {
            $warehouseExists = $this->salesDocumentValidationService->warehouseExists(
                $companyId,
                (int) $warehouseId,
                $branchId !== null ? (int) $branchId : null
            );

            if (!$warehouseExists) {
                throw new SalesDocumentException('Invalid warehouse scope');
            }
        }

        if ($cashRegisterId !== null) {
            $cashRegisterExists = $this->salesDocumentValidationService->cashRegisterExists(
                $companyId,
                (int) $cashRegisterId,
                $branchId !== null ? (int) $branchId : null
            );

            if (!$cashRegisterExists) {
                throw new SalesDocumentException('Invalid cash register scope');
            }
        }

        $documentKind = (string) ($payload['document_kind'] ?? '');
        $noteBaseKind = $this->salesBusinessRuleService->resolveNoteBaseKind($documentKind);
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $customerIdentity = $this->salesLookupService->fetchCustomerIdentityForSalesValidation($companyId, (int) $payload['customer_id']);

        if (!$customerIdentity) {
            throw new SalesDocumentException('Cliente no encontrado para emitir documento');
        }

        if (
            $this->salesBusinessRuleService->documentKindRequiresRucCustomer($documentKind)
            && !$this->salesBusinessRuleService->customerHasRucIdentity($customerIdentity)
        ) {
            throw new SalesDocumentException('Para este tipo de documento el cliente debe tener RUC valido (11 digitos).');
        }

        $allowReceiptRuc = $this->isCompanyFeatureEnabled($companyId, self::FEATURE_ALLOW_RECEIPT_RUC, false);
        if (
            $this->salesBusinessRuleService->documentKindDisallowsRucCustomer($documentKind, $allowReceiptRuc)
            && $this->salesBusinessRuleService->customerHasRucIdentity($customerIdentity)
        ) {
            throw new SalesDocumentException('Para boleta no se permite cliente con RUC. Active el flag de empresa si desea habilitar boleta con RUC.');
        }

        $selectedVehicleId = isset($payload['customer_vehicle_id']) ? (int) $payload['customer_vehicle_id'] : 0;
        if ($selectedVehicleId > 0 && !$workshopMultiVehicleEnabled) {
            throw new SalesDocumentException('La empresa no tiene habilitado el flujo de vehiculos por cliente.');
        }

        if ($selectedVehicleId > 0) {
            $vehicle = $this->salesDocumentValidationService->findActiveVehicle(
                $companyId,
                (int) $payload['customer_id'],
                $selectedVehicleId
            );

            if (!$vehicle) {
                throw new SalesDocumentException('El vehiculo seleccionado no pertenece al cliente o no esta activo.');
            }

            $payload['customer_vehicle_id'] = (int) $vehicle->id;
            $payload['vehicle_plate_snapshot'] = strtoupper(trim((string) ($vehicle->plate ?? '')));
            $payload['vehicle_brand_snapshot'] = trim((string) ($vehicle->brand ?? '')) !== '' ? trim((string) $vehicle->brand) : null;
            $payload['vehicle_model_snapshot'] = trim((string) ($vehicle->model ?? '')) !== '' ? trim((string) $vehicle->model) : null;
            $payload['metadata'] = array_merge($metadata, [
                'customer_vehicle_id' => (int) $vehicle->id,
                'vehicle_plate' => $payload['vehicle_plate_snapshot'],
                'vehicle_brand' => $payload['vehicle_brand_snapshot'],
                'vehicle_model' => $payload['vehicle_model_snapshot'],
            ]);
            $metadata = $payload['metadata'];
        }

        if ($noteBaseKind !== null) {
            $sourceDocumentId = isset($metadata['source_document_id']) ? (int) $metadata['source_document_id'] : 0;

            if ($sourceDocumentId <= 0) {
                throw new SalesDocumentException('Para nota de credito/debito debe indicar documento afectado');
            }

            $sourceDocument = $this->salesDocumentValidationService->findSourceDocument($companyId, $sourceDocumentId);

            if (!$sourceDocument) {
                throw new SalesDocumentException('Documento afectado no encontrado');
            }

            $sourceValidationError = $this->salesBusinessRuleService->validateSourceDocumentForNote(
                $sourceDocument,
                (int) $payload['customer_id']
            );

            if ($sourceValidationError !== null) {
                throw new SalesDocumentException($sourceValidationError);
            }

            $noteReasons = $this->resolveDocumentNoteReasons($noteBaseKind);
            if (count($noteReasons) === 0) {
                throw new SalesDocumentException('No hay maestro de tipos de nota configurado');
            }

            $resolvedReason = $this->salesBusinessRuleService->resolveNoteReason($noteReasons, $metadata);

            if ($resolvedReason === null) {
                throw new SalesDocumentException('Debe seleccionar un tipo de nota valido');
            }

            $payload['metadata'] = array_merge($metadata, [
                ...$this->salesBusinessRuleService->buildNoteMetadata($sourceDocument, $resolvedReason),
            ]);
        }

        return [
            'payload' => $payload,
            'branch_id' => $branchId,
            'warehouse_id' => $warehouseId,
            'cash_register_id' => $cashRegisterId,
        ];
    }

    private function resolveDocumentKindCodeById(int $documentKindId): ?string
    {
        $rows = $this->salesLookupService->listDocumentKindsCatalog();

        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) !== $documentKindId) {
                continue;
            }

            $code = strtoupper(trim((string) ($row['code'] ?? '')));
            return $code !== '' ? $code : null;
        }

        return null;
    }

    private function resolveDocumentNoteReasons(string $documentKind): array
    {
        $normalizedKind = $this->salesBusinessRuleService->resolveNoteBaseKind($documentKind) ?? strtoupper($documentKind);
        $rows = $this->salesLookupService->resolveDocumentNoteReasonsRows($normalizedKind);

        if (count($rows) === 0) {
            return $this->defaultDocumentNoteReasons($normalizedKind);
        }

        return $rows;
    }

    private function defaultDocumentNoteReasons(string $documentKind): array
    {
        if ($documentKind === 'DEBIT_NOTE') {
            return [
                ['id' => 1, 'code' => '01', 'description' => 'Interes por mora'],
                ['id' => 2, 'code' => '02', 'description' => 'Aumento en el valor'],
                ['id' => 3, 'code' => '03', 'description' => 'Penalidades u otros conceptos'],
            ];
        }

        return [
            ['id' => 1, 'code' => '01', 'description' => 'Anulacion de la operacion'],
            ['id' => 2, 'code' => '02', 'description' => 'Anulacion por error en el RUC'],
            ['id' => 3, 'code' => '03', 'description' => 'Correccion por error en la descripcion'],
            ['id' => 4, 'code' => '04', 'description' => 'Descuento global'],
            ['id' => 5, 'code' => '05', 'description' => 'Descuento por item'],
            ['id' => 6, 'code' => '06', 'description' => 'Devolucion total'],
            ['id' => 7, 'code' => '07', 'description' => 'Devolucion por item'],
            ['id' => 8, 'code' => '08', 'description' => 'Bonificacion'],
            ['id' => 9, 'code' => '09', 'description' => 'Disminucion en el valor'],
            ['id' => 10, 'code' => '10', 'description' => 'Otros conceptos'],
        ];
    }

    private function isCompanyFeatureEnabled(int $companyId, string $featureCode, bool $defaultValue): bool
    {
        $normalizedFeatureCode = strtoupper(trim($featureCode));
        if ($normalizedFeatureCode === '') {
            return $defaultValue;
        }

        $row = $this->salesLookupService->loadCompanyFeatureToggles($companyId)
            ->first(function ($toggle) use ($normalizedFeatureCode) {
                return strtoupper(trim((string) ($toggle->feature_code ?? ''))) === $normalizedFeatureCode;
            });

        if (!$row) {
            return $defaultValue;
        }

        return (bool) ($row->is_enabled ?? false);
    }
}
