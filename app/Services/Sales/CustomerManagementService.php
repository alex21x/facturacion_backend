<?php

namespace App\Services\Sales;

use App\Contracts\PadronLookupGateway;
use App\Domain\Sales\Repositories\CustomerRepositoryInterface;

class CustomerManagementService
{
    public function __construct(
        private CustomerRepositoryInterface $customerRepository,
        private PadronLookupGateway $padronLookupGateway
    ) {
    }

    public function resolveCustomerByDocument(int $companyId, string $document): array
    {
        $existing = $this->fetchCustomerRowByDocument($companyId, $document);
        if ($existing) {
            return [
                'status' => 200,
                'body' => [
                    'data' => $this->customerSuggestionFromRow($existing),
                    'source' => 'local',
                    'created' => false,
                    'message' => 'Cliente encontrado en base local.',
                ],
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
                        'message' => (string) ($lookup['message'] ?? 'Error al consultar padron externo.'),
                        'detail' => $lookup['detail'] ?? null,
                    ],
                ];
            }

            $fullName = (string) (($lookup['data']['full_name'] ?? '') ?: '');

            $customerId = $this->customerRepository->insertCustomer([
                'company_id' => $companyId,
                'doc_type' => '1',
                'customer_type_id' => $this->resolveCustomerTypeIdBySunatCode(1),
                'doc_number' => $document,
                'legal_name' => $fullName,
                'trade_name' => null,
                'first_name' => null,
                'last_name' => null,
                'plate' => null,
                'address' => 'LIMA',
                'status' => 1,
            ]);

            $created = $this->fetchCustomerRowById($companyId, (int) $customerId);
            if (!$created) {
                return ['status' => 500, 'body' => ['message' => 'No se pudo registrar el cliente consultado.']];
            }

            return [
                'status' => 200,
                'body' => [
                    'data' => $this->customerSuggestionFromRow($created),
                    'source' => $source,
                    'created' => true,
                    'message' => 'Cliente consultado y registrado correctamente.',
                ],
            ];
        }

        $lookup = $this->padronLookupGateway->consultRuc($document);
        if (!($lookup['ok'] ?? false)) {
            return [
                'status' => (int) ($lookup['status'] ?? 502),
                'body' => [
                    'message' => (string) ($lookup['message'] ?? 'Error al consultar padron externo.'),
                    'detail' => $lookup['detail'] ?? null,
                ],
            ];
        }

        $razon = (string) (($lookup['data']['legal_name'] ?? '') ?: '');
        $direccion = $lookup['data']['address'] ?? null;

        $customerId = $this->customerRepository->insertCustomer([
            'company_id' => $companyId,
            'doc_type' => '6',
            'customer_type_id' => $this->resolveCustomerTypeIdBySunatCode(6),
            'doc_number' => $document,
            'legal_name' => $razon,
            'trade_name' => null,
            'first_name' => null,
            'last_name' => null,
            'plate' => null,
            'address' => $direccion,
            'status' => 1,
        ]);

        $created = $this->fetchCustomerRowById($companyId, (int) $customerId);
        if (!$created) {
            return ['status' => 500, 'body' => ['message' => 'No se pudo registrar el cliente consultado.']];
        }

        return [
            'status' => 200,
            'body' => [
                'data' => $this->customerSuggestionFromRow($created),
                'source' => $source,
                'created' => true,
                'message' => 'Cliente consultado y registrado correctamente.',
            ],
        ];
    }

    public function createCustomer(int $companyId, array $payload): array
    {
        if (!array_key_exists('customer_type_id', $payload) && isset($payload['doc_type'])) {
            $docType = trim((string) $payload['doc_type']);
            if ($docType !== '' && is_numeric($docType)) {
                $typeId = $this->resolveCustomerTypeIdBySunatCode((int) $docType);
                if ($typeId !== null) {
                    $payload['customer_type_id'] = $typeId;
                }
            }
        }

        $validatedTierId = $this->resolveValidatedTierId($companyId, $payload['default_tier_id'] ?? null);
        if (array_key_exists('default_tier_id', $payload) && $payload['default_tier_id'] !== null && $validatedTierId === null) {
            return ['status' => 422, 'body' => ['message' => 'Invalid price tier for customer profile']];
        }

        $reactivated = false;
        $id = null;

        $docNumber = $payload['doc_number'] ?? null;
        if ($docNumber !== null && trim((string) $docNumber) !== '') {
            $existing = $this->customerRepository->findCustomerIdentityByDocument($companyId, (string) $docNumber);

            if ($existing) {
                if ((int) ($existing->status ?? 0) === 1) {
                    return [
                        'status' => 409,
                        'body' => [
                            'message' => 'Ya existe un cliente activo con ese documento. Puedes editarlo o buscarlo en la lista.',
                            'id' => (int) $existing->id,
                        ],
                    ];
                }

                $this->customerRepository->updateCustomerById($companyId, (int) $existing->id, [
                    'doc_type' => $payload['doc_type'] ?? null,
                    'customer_type_id' => $payload['customer_type_id'] ?? null,
                    'doc_number' => $payload['doc_number'] ?? null,
                    'legal_name' => $payload['legal_name'] ?? null,
                    'trade_name' => $payload['trade_name'] ?? null,
                    'first_name' => $payload['first_name'] ?? null,
                    'last_name' => $payload['last_name'] ?? null,
                    'plate' => $payload['plate'] ?? null,
                    'address' => $payload['address'] ?? null,
                    'phone' => $payload['phone'] ?? null,
                    'status' => 1,
                ]);

                $id = (int) $existing->id;
                $reactivated = true;
            }
        }

        if ($id === null) {
            $id = $this->customerRepository->insertCustomer([
                'company_id' => $companyId,
                'doc_type' => $payload['doc_type'] ?? null,
                'customer_type_id' => $payload['customer_type_id'] ?? null,
                'doc_number' => $payload['doc_number'] ?? null,
                'legal_name' => $payload['legal_name'] ?? null,
                'trade_name' => $payload['trade_name'] ?? null,
                'first_name' => $payload['first_name'] ?? null,
                'last_name' => $payload['last_name'] ?? null,
                'plate' => $payload['plate'] ?? null,
                'address' => $payload['address'] ?? null,
                'phone' => $payload['phone'] ?? null,
                'status' => (int) ($payload['status'] ?? 1),
            ]);
        }

        if (
            array_key_exists('default_tier_id', $payload)
            || array_key_exists('discount_percent', $payload)
            || array_key_exists('price_profile_status', $payload)
        ) {
            $this->customerRepository->upsertCustomerPriceProfile(
                $companyId,
                (int) $id,
                $validatedTierId,
                (float) ($payload['discount_percent'] ?? 0),
                (int) ($payload['price_profile_status'] ?? 1)
            );
        }

        return [
            'status' => $reactivated ? 200 : 201,
            'body' => [
                'message' => $reactivated ? 'Cliente reactivado correctamente.' : 'Customer created',
                'id' => (int) $id,
                'reactivated' => $reactivated,
            ],
        ];
    }

    public function bulkImportCustomers(int $companyId, array $rows): array
    {
        $activeTypesById = $this->customerRepository->getActiveCustomerTypeIdsMap();

        $existingByDoc = $this->customerRepository->getExistingCustomersByDocument($companyId);

        $seenInFile = [];
        $created = 0;
        $reactivated = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $docNumber = strtoupper(trim((string) ($row['doc_number'] ?? '')));
            $legalName = trim((string) ($row['legal_name'] ?? ''));

            if ($docNumber === '') {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'message' => 'Numero de documento es obligatorio.'];
                continue;
            }

            if ($legalName === '') {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'message' => 'Razon social / nombre es obligatorio.'];
                continue;
            }

            if (isset($seenInFile[$docNumber])) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'message' => 'Cliente duplicado dentro del archivo (mismo documento).'];
                continue;
            }
            $seenInFile[$docNumber] = true;

            $resolvedTypeId = null;
            $requestedTypeId = isset($row['customer_type_id']) ? (int) $row['customer_type_id'] : null;
            if ($requestedTypeId !== null && isset($activeTypesById[$requestedTypeId])) {
                $resolvedTypeId = $requestedTypeId;
            }

            if ($resolvedTypeId === null) {
                $resolvedSunatCode = $this->normalizeCustomerImportDocTypeToSunatCode(
                    isset($row['doc_type']) ? (string) $row['doc_type'] : null,
                    $docNumber
                );

                if ($resolvedSunatCode !== null) {
                    $resolvedTypeId = $this->resolveCustomerTypeIdBySunatCode($resolvedSunatCode);
                }
            }

            if ($resolvedTypeId === null) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'message' => 'No se pudo identificar el tipo de cliente (DNI/RUC/CE/PAS).'];
                continue;
            }

            $resolvedDocType = $this->customerRepository->getCustomerTypeSunatCodeById($resolvedTypeId);

            if ($resolvedDocType === null) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'message' => 'Tipo de cliente invalido para esta empresa.'];
                continue;
            }

            $payload = [
                'doc_type' => (string) ((int) $resolvedDocType),
                'customer_type_id' => $resolvedTypeId,
                'doc_number' => $docNumber,
                'legal_name' => $legalName,
                'trade_name' => $row['trade_name'] ?? null,
                'first_name' => $row['first_name'] ?? null,
                'last_name' => $row['last_name'] ?? null,
                'plate' => $row['plate'] ?? null,
                'address' => $row['address'] ?? null,
                'phone' => $row['phone'] ?? null,
                'status' => (int) ($row['status'] ?? 1),
            ];

            $existing = $existingByDoc[$docNumber] ?? null;
            if ($existing) {
                if ((int) ($existing['status'] ?? 0) === 1) {
                    $skipped++;
                    $errors[] = ['row' => $rowNumber, 'message' => 'Cliente ya existe activo con ese documento.'];
                    continue;
                }

                $this->customerRepository->updateCustomerById(
                    $companyId,
                    (int) $existing['id'],
                    array_merge($payload, ['status' => 1])
                );

                $existingByDoc[$docNumber] = ['id' => (int) $existing['id'], 'status' => 1];
                $reactivated++;
                continue;
            }

            $newId = $this->customerRepository->insertCustomer(array_merge($payload, [
                'company_id' => $companyId,
            ]));
            $existingByDoc[$docNumber] = ['id' => $newId, 'status' => 1];
            $created++;
        }

        return [
            'message' => 'Importacion de clientes procesada.',
            'summary' => [
                'total' => count($rows),
                'created' => $created,
                'reactivated' => $reactivated,
                'skipped' => $skipped,
                'errors' => count($errors),
            ],
            'errors' => array_slice($errors, 0, 300),
        ];
    }

    public function updateCustomer(int $companyId, int $id, array $changes): array
    {
        $exists = $this->customerRepository->customerExists($companyId, $id);

        if (!$exists) {
            return ['status' => 404, 'body' => ['message' => 'Customer not found']];
        }

        if (!array_key_exists('customer_type_id', $changes) && array_key_exists('doc_type', $changes)) {
            $docType = trim((string) $changes['doc_type']);
            if ($docType !== '' && is_numeric($docType)) {
                $typeId = $this->resolveCustomerTypeIdBySunatCode((int) $docType);
                if ($typeId !== null) {
                    $changes['customer_type_id'] = $typeId;
                }
            }
        }

        $profileRequested =
            array_key_exists('default_tier_id', $changes)
            || array_key_exists('discount_percent', $changes)
            || array_key_exists('price_profile_status', $changes);

        $validatedTierId = null;
        if (array_key_exists('default_tier_id', $changes)) {
            $validatedTierId = $this->resolveValidatedTierId($companyId, $changes['default_tier_id']);

            if ($changes['default_tier_id'] !== null && $validatedTierId === null) {
                return ['status' => 422, 'body' => ['message' => 'Invalid price tier for customer profile']];
            }
        }

        if ($profileRequested) {
            $currentProfile = $this->customerRepository->findCustomerPriceProfile($companyId, $id);

            $currentTierId = $currentProfile ? ($currentProfile->default_tier_id !== null ? (int) $currentProfile->default_tier_id : null) : null;
            $currentDiscount = $currentProfile ? (float) ($currentProfile->discount_percent ?? 0) : 0;
            $currentProfileStatus = $currentProfile ? (int) ($currentProfile->status ?? 1) : 1;

            $this->customerRepository->upsertCustomerPriceProfile(
                $companyId,
                $id,
                array_key_exists('default_tier_id', $changes)
                    ? $validatedTierId
                    : $currentTierId,
                array_key_exists('discount_percent', $changes)
                    ? (float) $changes['discount_percent']
                    : $currentDiscount,
                array_key_exists('price_profile_status', $changes)
                    ? (int) $changes['price_profile_status']
                    : $currentProfileStatus
            );
        }

        unset($changes['default_tier_id'], $changes['discount_percent'], $changes['price_profile_status']);

        if (empty($changes) && !$profileRequested) {
            return ['status' => 422, 'body' => ['message' => 'No changes provided']];
        }

        if (!empty($changes)) {
            $this->customerRepository->updateCustomerById($companyId, $id, $changes);
        }

        return ['status' => 200, 'body' => ['message' => 'Customer updated']];
    }

    private function fetchCustomerRowByDocument(int $companyId, string $document)
    {
        return $this->customerRepository->findCustomerByDocument($companyId, $document);
    }

    private function fetchCustomerRowById(int $companyId, int $id)
    {
        return $this->customerRepository->findCustomerById($companyId, $id);
    }

    private function customerSuggestionFromRow($row): array
    {
        $name = $row->legal_name;
        if (!$name) {
            $name = trim(collect([$row->first_name, $row->last_name])->filter()->implode(' '));
        }

        return [
            'id' => (int) $row->id,
            'doc_type' => $row->doc_type,
            'customer_type_id' => $row->customer_type_id !== null ? (int) $row->customer_type_id : null,
            'customer_type_name' => $row->customer_type_name,
            'customer_type_sunat_code' => $row->customer_type_sunat_code !== null ? (int) $row->customer_type_sunat_code : null,
            'doc_number' => $row->doc_number,
            'name' => $name ?: ('Cliente #' . $row->id),
            'trade_name' => $row->trade_name,
            'plate' => $row->plate,
            'address' => $row->address,
            'phone' => $row->phone,
            'default_tier_id' => $row->default_tier_id !== null ? (int) $row->default_tier_id : null,
            'default_tier_code' => $row->default_tier_code,
            'default_tier_name' => $row->default_tier_name,
            'discount_percent' => $row->discount_percent !== null ? (float) $row->discount_percent : 0,
            'price_profile_status' => $row->price_profile_status !== null ? (int) $row->price_profile_status : 1,
        ];
    }

    private function resolveCustomerTypeIdBySunatCode(int $sunatCode): ?int
    {
        return $this->customerRepository->resolveCustomerTypeIdBySunatCode($sunatCode);
    }

    private function normalizeCustomerImportDocTypeToSunatCode(?string $docTypeInput, string $docNumber): ?int
    {
        $raw = strtoupper(trim((string) ($docTypeInput ?? '')));
        $docDigits = preg_replace('/\D+/', '', $docNumber);
        if (!is_string($docDigits)) {
            $docDigits = '';
        }

        $aliases = [
            '1' => 1,
            'DNI' => 1,
            'NATURAL' => 1,
            'PERSONA NATURAL' => 1,
            '4' => 4,
            'CE' => 4,
            'CARNET' => 4,
            'CARNET DE EXTRANJERIA' => 4,
            'EXTRANJERIA' => 4,
            '6' => 6,
            'RUC' => 6,
            'JURIDICA' => 6,
            'PERSONA JURIDICA' => 6,
            '7' => 7,
            'PAS' => 7,
            'PASAPORTE' => 7,
        ];

        if ($raw !== '' && isset($aliases[$raw])) {
            return (int) $aliases[$raw];
        }

        if (strlen($docDigits) === 11) {
            return 6;
        }

        if (strlen($docDigits) === 8) {
            return 1;
        }

        return null;
    }

    private function resolveValidatedTierId(int $companyId, $tierId): ?int
    {
        if ($tierId === null || $tierId === '') {
            return null;
        }

        $resolved = (int) $tierId;
        if ($resolved <= 0) {
            return null;
        }

        return $this->customerRepository->tierExists($companyId, $resolved)
            ? $resolved
            : null;
    }
}
