<?php

namespace App\Services\Purchases;

use App\Contracts\PadronLookupGateway;
use App\Domain\Purchases\Repositories\SupplierRepositoryInterface;

class SupplierManagementService
{
    public function __construct(
        private SupplierRepositoryInterface $supplierRepository,
        private PadronLookupGateway $padronLookupGateway
    ) {
    }

    public function bulkImportSuppliers(int $companyId, array $rows): array
    {
        $existingDocs = $this->supplierRepository->getExistingSupplierDocumentSet($companyId);

        $seenInFile = [];
        $created = 0;
        $skipped = 0;
        $errors = [];
        $toInsert = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $docNumber = preg_replace('/\D+/', '', (string) ($row['doc_number'] ?? ''));
            if (!is_string($docNumber)) {
                $docNumber = '';
            }
            $docNumber = trim($docNumber);
            $legalName = trim((string) ($row['legal_name'] ?? ''));

            if ($docNumber === '') {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'message' => 'Numero de documento es obligatorio.'];
                continue;
            }

            if ($legalName === '') {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'message' => 'Nombre del proveedor es obligatorio.'];
                continue;
            }

            if (isset($seenInFile[$docNumber])) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'message' => 'Proveedor duplicado dentro del archivo (mismo documento).'];
                continue;
            }
            $seenInFile[$docNumber] = true;

            if (isset($existingDocs[$docNumber])) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'message' => 'Proveedor ya existe en el catalogo local (mismo documento).'];
                continue;
            }

            $docTypeInput = strtoupper(trim((string) ($row['doc_type'] ?? '')));
            $docType = $this->normalizeSupplierDocType($docTypeInput, $docNumber);

            $toInsert[] = [
                'company_id' => $companyId,
                'doc_type' => $docType,
                'doc_number' => $docNumber,
                'legal_name' => $legalName,
                'address' => $this->nullIfBlank((string) ($row['address'] ?? '')),
                'phone' => $this->nullIfBlank((string) ($row['phone'] ?? '')),
                'source' => $this->nullIfBlank((string) ($row['source'] ?? 'import')) ?? 'import',
                'created_at' => now(),
                'updated_at' => now(),
                'last_used_at' => now(),
            ];

            $existingDocs[$docNumber] = true;
            $created++;
        }

        foreach (array_chunk($toInsert, 500) as $chunk) {
            $this->supplierRepository->insertSupplierBatch($chunk);
        }

        return [
            'message' => 'Importacion de proveedores procesada.',
            'summary' => [
                'total' => count($rows),
                'created' => $created,
                'skipped' => $skipped,
                'errors' => count($errors),
            ],
            'errors' => array_slice($errors, 0, 300),
        ];
    }

    public function resolveSupplierByDocument(int $companyId, string $document): array
    {
        $existing = $this->fetchSupplierRowByDocument($companyId, $document);
        if ($existing) {
            return [
                'status' => 200,
                'body' => array_merge(
                    $this->supplierSuggestionFromRow($existing),
                    ['message' => 'Proveedor encontrado en base local.']
                ),
            ];
        }

        $isDni = strlen($document) === 8;
        $source = $isDni ? 'reniec' : 'sunat';

        if ($isDni) {
            $lookup = $this->padronLookupGateway->consultDni($document);
            if (!($lookup['ok'] ?? false)) {
                return [
                    'status' => (int) ($lookup['status'] ?? 502),
                    'body' => [
                        'message' => (string) ($lookup['message'] ?? 'Error al consultar el padron externo.'),
                    ],
                ];
            }

            $fullName = (string) (($lookup['data']['full_name'] ?? '') ?: '');

            $this->upsertPurchaseSupplier($companyId, [
                'doc_type' => 'DNI',
                'doc_number' => $document,
                'legal_name' => $fullName,
                'address' => null,
                'source' => $source,
            ]);

            $saved = $this->fetchSupplierRowByDocument($companyId, $document);
            return [
                'status' => 200,
                'body' => array_merge(
                    $this->supplierSuggestionFromRow($saved),
                    ['message' => 'Proveedor consultado y registrado correctamente.']
                ),
            ];
        }

        $lookup = $this->padronLookupGateway->consultRuc($document);
        if (!($lookup['ok'] ?? false)) {
            return [
                'status' => (int) ($lookup['status'] ?? 502),
                'body' => [
                    'message' => (string) ($lookup['message'] ?? 'Error al consultar el padron externo.'),
                ],
            ];
        }

        $razon = (string) (($lookup['data']['legal_name'] ?? '') ?: '');
        $direccion = $lookup['data']['address'] ?? null;

        $this->upsertPurchaseSupplier($companyId, [
            'doc_type' => 'RUC',
            'doc_number' => $document,
            'legal_name' => $razon,
            'address' => $direccion,
            'source' => $source,
        ]);

        $saved = $this->fetchSupplierRowByDocument($companyId, $document);
        return [
            'status' => 200,
            'body' => array_merge(
                $this->supplierSuggestionFromRow($saved),
                ['message' => 'Proveedor consultado y registrado correctamente.']
            ),
        ];
    }

    private function fetchSupplierRowByDocument(int $companyId, string $document)
    {
        return $this->supplierRepository->findSupplierByDocument($companyId, $document);
    }

    private function upsertPurchaseSupplier(int $companyId, array $data): void
    {
        $this->supplierRepository->upsertSupplierByDocument($companyId, $data);
    }

    private function supplierSuggestionFromRow($row): array
    {
        return [
            'id' => isset($row->id) ? (int) $row->id : 0,
            'doc_type' => isset($row->doc_type) ? (string) $row->doc_type : null,
            'doc_number' => isset($row->doc_number) ? (string) $row->doc_number : '',
            'name' => isset($row->legal_name) ? (string) $row->legal_name : '',
            'address' => isset($row->address) ? (string) $row->address : null,
            'phone' => isset($row->phone) ? (string) $row->phone : null,
            'source' => isset($row->source) ? (string) $row->source : 'local',
        ];
    }

    private function normalizeSupplierDocType(string $docTypeInput, string $docNumber): string
    {
        if (in_array($docTypeInput, ['RUC', 'DNI', 'CE', 'PAS'], true)) {
            return $docTypeInput;
        }

        if (strlen($docNumber) === 11) {
            return 'RUC';
        }

        if (strlen($docNumber) === 8) {
            return 'DNI';
        }

        return 'OTR';
    }

    private function nullIfBlank(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
