<?php

namespace App\Services\Sales\Documents;

use App\Domain\Sales\Policies\CommercialDocumentPolicy;
use DomainException;
use Illuminate\Support\Facades\DB;

class SalesDocumentTaxMetadataService
{
    public function __construct(private SalesDocumentSupportService $support)
    {
    }

    public function validateAndEnrich(array $metadata, string $documentKind, float $grandTotal, int $companyId, ?int $branchId): array
    {
        try {
            CommercialDocumentPolicy::assertSingleTaxCondition($metadata);
        } catch (DomainException $e) {
            throw new SalesDocumentException($e->getMessage(), 422);
        }

        if (!empty($metadata['has_detraccion'])) {
            if (!$this->support->isCommerceFeatureEnabledForContextWithDefault($companyId, $branchId, 'SALES_DETRACCION_ENABLED', false)) {
                throw new SalesDocumentException('Las detracciones no están habilitadas para esta empresa/sucursal.');
            }

            if (!$this->isInvoiceDocumentKind($documentKind)) {
                throw new SalesDocumentException('La detracción solo aplica a Facturas (INVOICE).');
            }

            $detraccionCode = trim((string) ($metadata['detraccion_service_code'] ?? ''));
            if ($detraccionCode === '') {
                throw new SalesDocumentException('Se requiere el código de bien/servicio sujeto a detracción.');
            }

            $serviceRow = $this->tableExists('master.detraccion_service_codes')
                ? DB::table('master.detraccion_service_codes')
                    ->where('code', $detraccionCode)
                    ->where('is_active', 1)
                    ->first()
                : null;

            if (!$serviceRow) {
                throw new SalesDocumentException('Código de detracción inválido: ' . $detraccionCode);
            }

            $detraccionRate = (float) $serviceRow->rate_percent;
            $detraccionAmount = round($grandTotal * $detraccionRate / 100, 2);
            $detraccionAccount = $this->resolveFeatureAccountInfo($companyId, $branchId, 'SALES_DETRACCION_ENABLED', 'DETRACCION');

            $metadata['detraccion_service_name'] = $serviceRow->name;
            $metadata['detraccion_rate_percent'] = $detraccionRate;
            $metadata['detraccion_amount'] = $detraccionAmount;
            if ($detraccionAccount) {
                $metadata['detraccion_account_number'] = (string) ($detraccionAccount['account_number'] ?? '');
                $metadata['detraccion_bank_name'] = (string) ($detraccionAccount['bank_name'] ?? '');
            }
        }

        $sunatOperationTypeCode = strtoupper(trim((string) ($metadata['sunat_operation_type_code'] ?? '')));
        if (!empty($metadata['has_detraccion']) || !empty($metadata['has_retencion']) || !empty($metadata['has_percepcion'])) {
            $sunatOperationTypes = $this->resolveSunatOperationTypes($companyId, $branchId);
            if ($sunatOperationTypeCode === '') {
                $sunatOperationTypeCode = (string) ($sunatOperationTypes[0]['code'] ?? '');
            }

            $selectedOperationType = collect($sunatOperationTypes)->first(function ($row) use ($sunatOperationTypeCode) {
                return strtoupper((string) ($row['code'] ?? '')) === $sunatOperationTypeCode;
            });

            if ($selectedOperationType) {
                $metadata['sunat_operation_type_code'] = (string) ($selectedOperationType['code'] ?? '');
                $metadata['sunat_operation_type_name'] = (string) ($selectedOperationType['name'] ?? '');
            }
        }

        if (!empty($metadata['has_retencion'])) {
            if (!$this->support->isCommerceFeatureEnabledForContextWithDefault($companyId, $branchId, 'SALES_RETENCION_ENABLED', false)) {
                throw new SalesDocumentException('La retención no está habilitada para esta empresa/sucursal.');
            }

            if (!$this->isInvoiceDocumentKind($documentKind)) {
                throw new SalesDocumentException('La retención de IGV solo aplica a Facturas (INVOICE).');
            }

            $retencionTypeCode = strtoupper(trim((string) ($metadata['retencion_type_code'] ?? '')));
            $retencionTypes = $this->resolveRetencionTypes($companyId, $branchId);
            if ($retencionTypeCode === '') {
                $retencionTypeCode = (string) ($retencionTypes[0]['code'] ?? '');
            }

            $selectedRetencionType = collect($retencionTypes)->first(function ($row) use ($retencionTypeCode) {
                return strtoupper((string) ($row['code'] ?? '')) === $retencionTypeCode;
            });

            if (!$selectedRetencionType) {
                throw new SalesDocumentException('Tipo de retención inválido.');
            }

            $retencionRate = (float) ($selectedRetencionType['rate_percent'] ?? 3.00);
            $retencionAmount = round($grandTotal * $retencionRate / 100, 2);
            $retencionAccount = $this->resolveFeatureAccountInfo($companyId, $branchId, 'SALES_RETENCION_ENABLED', 'RETENCION');

            $metadata['retencion_type_code'] = $retencionTypeCode;
            $metadata['retencion_type_name'] = (string) ($selectedRetencionType['name'] ?? 'Retencion IGV');
            $metadata['retencion_rate_percent'] = $retencionRate;
            $metadata['retencion_amount'] = $retencionAmount;
            if ($retencionAccount) {
                $metadata['retencion_account_number'] = (string) ($retencionAccount['account_number'] ?? '');
                $metadata['retencion_bank_name'] = (string) ($retencionAccount['bank_name'] ?? '');
            }
        }

        if (!empty($metadata['has_percepcion'])) {
            if (!$this->support->isCommerceFeatureEnabledForContextWithDefault($companyId, $branchId, 'SALES_PERCEPCION_ENABLED', false)) {
                throw new SalesDocumentException('La percepción no está habilitada para esta empresa/sucursal.');
            }

            if (!$this->isInvoiceDocumentKind($documentKind)) {
                throw new SalesDocumentException('La percepción solo aplica a Facturas (INVOICE).');
            }

            $percepcionTypeCode = strtoupper(trim((string) ($metadata['percepcion_type_code'] ?? '')));
            $percepcionTypes = $this->resolvePercepcionTypes($companyId, $branchId);
            if ($percepcionTypeCode === '') {
                $percepcionTypeCode = (string) ($percepcionTypes[0]['code'] ?? '');
            }

            $selectedPercepcionType = collect($percepcionTypes)->first(function ($row) use ($percepcionTypeCode) {
                return strtoupper((string) ($row['code'] ?? '')) === $percepcionTypeCode;
            });

            if (!$selectedPercepcionType) {
                throw new SalesDocumentException('Tipo de percepción inválido.');
            }

            $percepcionRate = (float) ($selectedPercepcionType['rate_percent'] ?? 2.00);
            $percepcionAmount = round($grandTotal * $percepcionRate / 100, 2);
            $percepcionAccount = $this->resolveFeatureAccountInfo($companyId, $branchId, 'SALES_PERCEPCION_ENABLED', 'PERCEPCION');

            $metadata['percepcion_type_code'] = $percepcionTypeCode;
            $metadata['percepcion_type_name'] = (string) ($selectedPercepcionType['name'] ?? 'Percepcion');
            $metadata['percepcion_rate_percent'] = $percepcionRate;
            $metadata['percepcion_amount'] = $percepcionAmount;
            if ($percepcionAccount) {
                $metadata['percepcion_account_number'] = (string) ($percepcionAccount['account_number'] ?? '');
                $metadata['percepcion_bank_name'] = (string) ($percepcionAccount['bank_name'] ?? '');
            }
        }

        return $metadata;
    }

    private function resolveRetencionTypes(int $companyId, ?int $branchId): array
    {
        $defaultRate = 3.00;
        $defaultType = ['code' => 'RET_IGV_3', 'name' => 'Retencion IGV', 'rate_percent' => $defaultRate];
        $featureRow = $this->resolveFeatureToggleRow($companyId, $branchId, 'SALES_RETENCION_ENABLED');
        $config = $this->decodeFeatureConfig($featureRow ? $featureRow->config : null);
        $configuredTypes = isset($config['retencion_types']) && is_array($config['retencion_types']) ? $config['retencion_types'] : [];

        $rows = collect($configuredTypes)
            ->map(function ($item) use ($defaultRate) {
                if (!is_array($item)) {
                    return null;
                }

                return [
                    'code' => strtoupper(trim((string) ($item['code'] ?? ''))),
                    'name' => trim((string) ($item['name'] ?? '')),
                    'rate_percent' => isset($item['rate_percent']) && is_numeric($item['rate_percent'])
                        ? (float) $item['rate_percent']
                        : $defaultRate,
                ];
            })
            ->filter(fn ($row) => is_array($row) && $row['code'] !== '' && $row['name'] !== '')
            ->values()
            ->all();

        return count($rows) > 0 ? $rows : [$defaultType];
    }

    private function resolvePercepcionTypes(int $companyId, ?int $branchId): array
    {
        $defaultRate = 2.00;
        $defaultType = ['code' => 'PERC_IGV_2', 'name' => 'Percepcion IGV', 'rate_percent' => $defaultRate];
        $featureRow = $this->resolveFeatureToggleRow($companyId, $branchId, 'SALES_PERCEPCION_ENABLED');
        $config = $this->decodeFeatureConfig($featureRow ? $featureRow->config : null);
        $configuredTypes = isset($config['percepcion_types']) && is_array($config['percepcion_types']) ? $config['percepcion_types'] : [];

        $rows = collect($configuredTypes)
            ->map(function ($item) use ($defaultRate) {
                if (!is_array($item)) {
                    return null;
                }

                return [
                    'code' => strtoupper(trim((string) ($item['code'] ?? ''))),
                    'name' => trim((string) ($item['name'] ?? '')),
                    'rate_percent' => isset($item['rate_percent']) && is_numeric($item['rate_percent'])
                        ? (float) $item['rate_percent']
                        : $defaultRate,
                ];
            })
            ->filter(fn ($row) => is_array($row) && $row['code'] !== '' && $row['name'] !== '')
            ->values()
            ->all();

        return count($rows) > 0 ? $rows : [$defaultType];
    }

    private function resolveSunatOperationTypes(int $companyId, ?int $branchId): array
    {
        $catalogRows = $this->resolveSunatOperationTypesFromCatalog();
        if (count($catalogRows) > 0) {
            return $catalogRows;
        }

        $detraccionConfig = $this->decodeFeatureConfig(optional($this->resolveFeatureToggleRow($companyId, $branchId, 'SALES_DETRACCION_ENABLED'))->config);
        $retencionConfig = $this->decodeFeatureConfig(optional($this->resolveFeatureToggleRow($companyId, $branchId, 'SALES_RETENCION_ENABLED'))->config);
        $percepcionConfig = $this->decodeFeatureConfig(optional($this->resolveFeatureToggleRow($companyId, $branchId, 'SALES_PERCEPCION_ENABLED'))->config);

        $configuredRows = [];
        if (isset($detraccionConfig['sunat_operation_types']) && is_array($detraccionConfig['sunat_operation_types'])) {
            $configuredRows = array_merge($configuredRows, $detraccionConfig['sunat_operation_types']);
        }
        if (isset($retencionConfig['sunat_operation_types']) && is_array($retencionConfig['sunat_operation_types'])) {
            $configuredRows = array_merge($configuredRows, $retencionConfig['sunat_operation_types']);
        }
        if (isset($percepcionConfig['sunat_operation_types']) && is_array($percepcionConfig['sunat_operation_types'])) {
            $configuredRows = array_merge($configuredRows, $percepcionConfig['sunat_operation_types']);
        }

        $rows = collect($configuredRows)
            ->map(function ($item) {
                if (!is_array($item)) {
                    return null;
                }

                $regime = strtoupper(trim((string) ($item['regime'] ?? 'NONE')));
                if (!in_array($regime, ['NONE', 'DETRACCION', 'RETENCION', 'PERCEPCION'], true)) {
                    $regime = 'NONE';
                }

                return [
                    'code' => strtoupper(trim((string) ($item['code'] ?? ''))),
                    'name' => trim((string) ($item['name'] ?? '')),
                    'regime' => $regime,
                ];
            })
            ->filter(fn ($row) => is_array($row) && $row['code'] !== '' && $row['name'] !== '')
            ->unique('code')
            ->values()
            ->all();

        if (count($rows) > 0) {
            return $rows;
        }

        return [
            ['code' => '0101', 'name' => 'Venta interna', 'regime' => 'NONE'],
            ['code' => '1001', 'name' => 'Operacion sujeta a detraccion', 'regime' => 'DETRACCION'],
            ['code' => '2001', 'name' => 'Operacion sujeta a retencion', 'regime' => 'RETENCION'],
            ['code' => '3001', 'name' => 'Operacion sujeta a percepcion', 'regime' => 'PERCEPCION'],
        ];
    }

    private function resolveSunatOperationTypesFromCatalog(): array
    {
        if (!$this->tableExists('master.sunat_operation_types')) {
            return [];
        }

        return DB::table('master.sunat_operation_types')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get(['code', 'name', 'regime'])
            ->map(function ($row) {
                $regime = strtoupper(trim((string) ($row->regime ?? 'NONE')));
                if (!in_array($regime, ['NONE', 'DETRACCION', 'RETENCION', 'PERCEPCION'], true)) {
                    $regime = 'NONE';
                }

                return [
                    'code' => strtoupper(trim((string) ($row->code ?? ''))),
                    'name' => trim((string) ($row->name ?? '')),
                    'regime' => $regime,
                ];
            })
            ->filter(fn ($row) => is_array($row) && $row['code'] !== '' && $row['name'] !== '')
            ->values()
            ->all();
    }

    private function resolveFeatureAccountInfo(int $companyId, ?int $branchId, string $featureCode, string $fallbackKeyword): ?array
    {
        $featureRow = $this->resolveFeatureToggleRow($companyId, $branchId, $featureCode);
        $config = $this->decodeFeatureConfig($featureRow ? $featureRow->config : null);
        $accountNumber = trim((string) ($config['account_number'] ?? ''));
        if ($accountNumber !== '') {
            return [
                'bank_name' => trim((string) ($config['bank_name'] ?? '')),
                'account_number' => $accountNumber,
                'account_holder' => trim((string) ($config['account_holder'] ?? '')),
            ];
        }

        $keyword = strtoupper(trim($fallbackKeyword));
        foreach ($this->resolveCompanyBankAccounts($companyId) as $account) {
            $accountType = strtoupper(trim((string) ($account['account_type'] ?? '')));
            $number = trim((string) ($account['account_number'] ?? ''));
            if ($number === '') {
                continue;
            }
            if ($keyword !== '' && strpos($accountType, $keyword) === false) {
                continue;
            }

            return [
                'bank_name' => trim((string) ($account['bank_name'] ?? '')),
                'account_number' => $number,
                'account_holder' => trim((string) ($account['account_holder'] ?? '')),
            ];
        }

        return null;
    }

    private function resolveCompanyBankAccounts(int $companyId): array
    {
        if (!$this->tableExists('core.company_settings')) {
            return [];
        }

        $row = DB::table('core.company_settings')
            ->where('company_id', $companyId)
            ->select('bank_accounts')
            ->first();

        if (!$row || $row->bank_accounts === null) {
            return [];
        }

        $decoded = is_string($row->bank_accounts)
            ? json_decode($row->bank_accounts, true)
            : (array) $row->bank_accounts;

        return is_array($decoded) ? array_values(array_filter($decoded, fn ($item) => is_array($item))) : [];
    }

    private function resolveFeatureToggleRow(int $companyId, ?int $branchId, string $featureCode)
    {
        $companyRow = DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->where('feature_code', $featureCode)
            ->first();

        if ($branchId !== null) {
            $branchRow = DB::table('appcfg.branch_feature_toggles')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('feature_code', $featureCode)
                ->first();

            if ($branchRow && (bool) ($branchRow->is_enabled ?? false)) {
                return $branchRow;
            }
            if ($companyRow && (bool) ($companyRow->is_enabled ?? false)) {
                return $companyRow;
            }
            if ($branchRow) {
                return $branchRow;
            }
        }

        return $companyRow;
    }

    private function decodeFeatureConfig($rawConfig): array
    {
        if ($rawConfig === null) {
            return [];
        }
        if (is_string($rawConfig)) {
            $decoded = json_decode($rawConfig, true);
            return is_array($decoded) ? $decoded : [];
        }

        return is_array($rawConfig) ? $rawConfig : [];
    }

    private function isInvoiceDocumentKind(string $documentKind): bool
    {
        $normalized = strtoupper(trim($documentKind));

        if ($this->tableExists('sales.document_kinds')) {
            $row = DB::table('sales.document_kinds')
                ->whereRaw('UPPER(TRIM(code)) = ?', [$normalized])
                ->select('sunat_code')
                ->first();

            if ($row !== null) {
                return (string) ($row->sunat_code ?? '') === '01';
            }
        }

        return $normalized === 'INVOICE';
    }

    private function tableExists(string $qualifiedTable): bool
    {
        [$schema, $table] = strpos($qualifiedTable, '.') === false
            ? ['public', $qualifiedTable]
            : explode('.', $qualifiedTable, 2);

        $row = DB::selectOne(
            'select exists (select 1 from information_schema.tables where table_schema = ? and table_name = ?) as present',
            [$schema, $table]
        );

        return isset($row->present) && (bool) $row->present;
    }
}
