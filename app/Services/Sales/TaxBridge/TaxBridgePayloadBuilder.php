<?php

namespace App\Services\Sales\TaxBridge;

use App\Services\AppConfig\CompanyIgvRateService;
use Illuminate\Support\Facades\DB;

class TaxBridgePayloadBuilder
{
    public function __construct(private CompanyIgvRateService $companyIgvRateService)
    {
    }

    public function build(int $companyId, int $documentId, array $bridgeConfig): ?array
    {
        $document = DB::table('sales.commercial_documents as d')
            ->join('core.companies as co', 'co.id', '=', 'd.company_id')
            ->leftJoin('core.company_settings as cs', 'cs.company_id', '=', 'd.company_id')
            ->leftJoin('core.currencies as cur', 'cur.id', '=', 'd.currency_id')
            ->leftJoin('sales.customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('sales.customer_types as ct', 'ct.id', '=', 'c.customer_type_id')
            ->where('d.id', $documentId)
            ->where('d.company_id', $companyId)
            ->select(
                'd.id',
                'd.document_kind',
                'd.document_kind_id',
                'd.series',
                'd.number',
                'd.issue_at',
                'd.subtotal',
                'd.discount_total',
                'd.tax_total',
                'd.total',
                'd.reference_document_id',
                'd.reference_reason_code',
                'd.metadata',
                'co.tax_id as company_tax_id',
                'co.legal_name as company_legal_name',
                'co.trade_name as company_trade_name',
                'co.address as company_address',
                'cs.address as settings_address',
                'cs.phone as settings_phone',
                'cs.email as settings_email',
                'cs.extra_data as settings_extra_data',
                'cur.code as currency_code',
                'cur.name as currency_name',
                'c.doc_type as customer_doc_type',
                'c.customer_type_id as customer_type_id',
                'ct.sunat_code as customer_sunat_doc_type_code',
                'c.doc_number as customer_doc_number',
                'c.legal_name as customer_legal_name',
                'c.first_name as customer_first_name',
                'c.last_name as customer_last_name',
                'c.address as customer_address'
            )
            ->first();

        if (!$document) {
            return null;
        }

        $normalizedDocumentKind = $this->resolveDocumentBaseKind(
            (string) $document->document_kind,
            $document->document_kind_id !== null ? (int) $document->document_kind_id : null
        );

        $docTypeMap = [
            'INVOICE' => '01',
            'RECEIPT' => '03',
            'CREDIT_NOTE' => '07',
            'DEBIT_NOTE' => '08',
        ];

        $tipoDocumento = $docTypeMap[$normalizedDocumentKind] ?? null;
        if ($tipoDocumento === null) {
            return null;
        }

        $meta = json_decode((string) ($document->metadata ?? '{}'), true);
        if (!is_array($meta)) {
            $meta = [];
        }

        $companyExtra = $this->decodeJsonObject($document->settings_extra_data ?? null);
        $companyAddress = trim((string) ($document->settings_address ?: $document->company_address ?: ''));
        $companyUrbanizacion = trim((string) ($companyExtra['urbanizacion'] ?? ''));
        $companyUbigeo = trim((string) ($companyExtra['ubigeo'] ?? ''));
        $companyDepartamento = trim((string) ($companyExtra['departamento'] ?? ''));
        $companyProvincia = trim((string) ($companyExtra['provincia'] ?? ''));
        $companyDistrito = trim((string) ($companyExtra['distrito'] ?? ''));
        $companyCodigoLocal = trim((string) ($bridgeConfig['codigolocal'] ?? ''));
        $companyPhone = trim((string) ($document->settings_phone ?? ''));
        $companyEmail = trim((string) ($document->settings_email ?? ''));
        $configuredIgvRate = $this->companyIgvRateService->resolveActiveRatePercent($companyId);

        $items = DB::table('sales.commercial_document_items')
            ->where('document_id', $documentId)
            ->orderBy('line_no')
            ->get();

        $taxCategoryMap = $this->resolveTaxCategoryMap($companyId);

        $detalle = [];
        $totalGravadas = 0.0;
        $totalInafectas = 0.0;
        $totalExoneradas = 0.0;
        $totalIcbper = 0.0;
        foreach ($items as $item) {
            $lineMeta = json_decode((string) ($item->metadata ?? '{}'), true);
            $lineMeta = is_array($lineMeta) ? $lineMeta : [];

            $lineSubtotal = (float) ($item->subtotal ?? 0);
            $lineTax = (float) ($item->tax_total ?? 0);
            $qty = (float) ($item->qty ?? 0);
            $unitPrice = (float) ($item->unit_price ?? 0);
            $taxCategoryId = isset($item->tax_category_id) ? (int) $item->tax_category_id : 0;
            $taxCategory = $taxCategoryId > 0 ? ($taxCategoryMap[$taxCategoryId] ?? null) : null;
            $tipoIgv = $this->resolveLineTipoIgv($lineMeta, $taxCategory, $lineTax);
            $sunatCode = trim((string) ($lineMeta['sunat'] ?? $lineMeta['sunat_code'] ?? ''));
            $unidad = trim((string) ($lineMeta['unidad'] ?? 'NIU'));
            $icbperEnabled = !empty($lineMeta['icbper']) || !empty($lineMeta['has_icbper']);
            $icbperValue = (float) ($lineMeta['valor_icbper'] ?? 0);
            $icbperTax = (float) ($lineMeta['igv_icbper'] ?? 0);
            $gratuitas = (float) ($lineMeta['gratuitas'] ?? $lineMeta['free_operation_total'] ?? 0);
            $descuento = (float) ($lineMeta['descuento'] ?? $item->discount_total ?? 0);

            if (in_array($tipoIgv, ['10', '11', '12', '13', '14', '15', '16', '17'], true)) {
                $totalGravadas += $lineSubtotal;
            } elseif (in_array($tipoIgv, ['20', '21'], true)) {
                $totalExoneradas += $lineSubtotal;
            } elseif (in_array($tipoIgv, ['30', '31', '32', '33', '34', '35', '36'], true)) {
                $totalInafectas += $lineSubtotal;
            }

            $totalIcbper += $icbperTax;

            $detalle[] = [
                'sunat' => $sunatCode,
                'codigo' => (string) ($item->product_id ?? '000000000'),
                'unidad' => $unidad !== '' ? $unidad : 'NIU',
                'cantidad' => round($qty, 4),
                'descripcion' => (string) ($item->description ?? ''),
                'tipo_igv' => $tipoIgv,
                'base' => round($lineSubtotal, 2),
                'igv' => round($lineTax, 2),
                'impuestos' => round($lineTax, 2),
                'valor_venta' => round($lineSubtotal, 2),
                'valor_unitario' => $qty > 0 ? round($lineSubtotal / $qty, 10) : 0,
                'precio_unitario' => round($unitPrice, 2),
                'porcentaje_igv' => $this->resolveLineIgvPercent($tipoIgv, $lineSubtotal, $lineTax, $taxCategory, $configuredIgvRate),
                'icbper' => $icbperEnabled ? 'on' : 'off',
                'valor_icbper' => round($icbperValue, 2),
                'igv_icbper' => round($icbperTax, 2),
                'gratuitas' => round($gratuitas, 2),
                'descuento' => round($descuento, 2),
            ];
        }

        $sourceDocumentId = (int) ($meta['source_document_id'] ?? $document->reference_document_id ?? 0);
        $sourceDocument = null;
        if ($sourceDocumentId > 0) {
            $sourceDocument = DB::table('sales.commercial_documents')
                ->select('id', 'document_kind', 'series', 'number')
                ->where('id', $sourceDocumentId)
                ->where('company_id', $companyId)
                ->first();
        }

        $payments = DB::table('sales.commercial_document_payments')
            ->where('document_id', $documentId)
            ->orderBy('id')
            ->get();

        $detallePagos = [];
        $totalCredito = 0;
        $j = 1;
        foreach ($payments as $payment) {
            if ((string) ($payment->status ?? 'PENDING') === 'PENDING') {
                $monto = (float) ($payment->amount ?? 0);
                $totalCredito += $monto;
                $detallePagos[] = [
                    'monto' => round($monto, 2),
                    'fecha_cuota' => $payment->due_at ? date('Y-m-d', strtotime((string) $payment->due_at)) : date('Y-m-d'),
                    'N°cuota' => 'Cuota00' . $j,
                ];
                $j++;
            }
        }

        $isCredit = count($detallePagos) > 0;
        $customerName = trim((string) ($document->customer_legal_name ?? ''));
        if ($customerName === '') {
            $customerName = trim((string) ($document->customer_first_name ?? '') . ' ' . (string) ($document->customer_last_name ?? ''));
        }

        $customerDocTypeCode = $document->customer_sunat_doc_type_code !== null
            ? (int) $document->customer_sunat_doc_type_code
            : $this->resolveCustomerDocTypeCode((string) ($document->customer_doc_type ?? '6'));
        $customerDocNumber = trim((string) ($document->customer_doc_number ?? ''));

        $detraccionEnabled = !empty($meta['has_detraccion']);
        $retencionEnabled = !empty($meta['has_retencion']);
        $percepcionEnabled = !empty($meta['has_percepcion']);
        $bridgeUser = trim((string) (($bridgeConfig['sunat_secondary_user'] ?? '') ?: ($bridgeConfig['sol_user'] ?? '')));
        $bridgePass = (string) (($bridgeConfig['sunat_secondary_pass'] ?? '') !== ''
            ? $bridgeConfig['sunat_secondary_pass']
            : ($bridgeConfig['sol_pass'] ?? ''));

        return [
            'empresa' => [
                'ruc' => (string) ($document->company_tax_id ?? ''),
                'user' => $bridgeUser,
                'pass' => $bridgePass,
                'razon_social' => (string) ($document->company_legal_name ?? ''),
                'nombre_comercial' => (string) ($document->company_trade_name ?? ''),
                'direccion' => $companyAddress,
                'urbanizacion' => $companyUrbanizacion,
                'ubigeo' => $companyUbigeo,
                'departamento' => $companyDepartamento,
                'provincia' => $companyProvincia,
                'distrito' => $companyDistrito,
                'codigolocal' => $companyCodigoLocal,
                'telefono_fijo' => $companyPhone,
                'correo' => $companyEmail,
                'envio_pse' => (string) ($bridgeConfig['envio_pse'] ?? ''),
            ],
            'cliente' => [
                'ruc' => $customerDocNumber,
                'tipo_documento' => (string) $customerDocTypeCode,
                'razon_social' => $customerName,
            ],
            'cabecera' => [
                'tipo_operacion' => (string) ($meta['sunat_operation_type_code'] ?? '0101'),
                'tipo_documento' => $tipoDocumento,
                'serie' => (string) $document->series,
                'numero' => (string) $document->number,
                'fecha_emision' => date('Y-m-d H:i:s', strtotime((string) $document->issue_at)),
                'tipo_moneda' => (string) ($document->currency_code ?? 'PEN'),
                'gravadas' => round($totalGravadas, 2),
                'inafectas' => round($totalInafectas, 2),
                'exoneradas' => round($totalExoneradas, 2),
                'igv' => round((float) ($document->tax_total ?? 0), 2),
                'icbper' => round($totalIcbper, 2),
                'impuestos' => round((float) ($document->tax_total ?? 0) + $totalIcbper, 2),
                'importe_venta' => round((float) ($document->total ?? 0), 2),
                'descuentoGlobal' => round((float) ($meta['discount_total'] ?? $document->discount_total ?? 0), 2),
                'observaciones' => trim((string) ($meta['observaciones'] ?? '')),
                'cod_motivo' => (string) ($meta['note_reason_code'] ?? $document->reference_reason_code ?? ''),
                'des_motivo' => (string) ($meta['note_reason_description'] ?? ''),
            ],
            'Pago' => [
                'FormaPago' => $isCredit ? 'Credito' : 'Contado',
                'Monto' => $isCredit ? round($totalCredito, 2) : round((float) ($document->total ?? 0), 2),
            ],
            'detalle_pagos' => $detallePagos,
            'Detraccion' => [
                'Estado' => $detraccionEnabled ? 'on' : 'off',
                'CodigoDetraccion' => (string) ($meta['detraccion_service_code'] ?? ''),
                'CodigoMedioPago' => (string) ($meta['detraccion_payment_method_code'] ?? '001'),
                'NumeroCuentaDetraccion' => (string) ($meta['detraccion_account_number'] ?? ''),
                'PorcentajeDetraccion' => round((float) ($meta['detraccion_rate_percent'] ?? 0), 2),
                'TotalDetraccion' => round((float) ($meta['detraccion_amount'] ?? 0), 2),
            ],
            'Retencion' => [
                'Estado' => $retencionEnabled ? 'on' : 'off',
                'CodigoRetencion' => (string) ($meta['retencion_type_code'] ?? ''),
                'PorcentajeRetencion' => round((float) ($meta['retencion_rate_percent'] ?? 0), 2),
                'TotalRetencion' => round((float) ($meta['retencion_amount'] ?? 0), 2),
            ],
            'Percepcion' => [
                'Estado' => $percepcionEnabled ? 'on' : 'off',
                'CodigoPercepcion' => (string) ($meta['percepcion_type_code'] ?? ''),
                'PorcentajePercepcion' => round((float) ($meta['percepcion_rate_percent'] ?? 0), 2),
                'TotalPercepcion' => round((float) ($meta['percepcion_amount'] ?? 0), 2),
            ],
            'adjunto' => $sourceDocument ? [
                'tipo_documento' => $this->legacyDocumentCode((string) ($sourceDocument->document_kind ?? '')),
                'serie' => (string) ($sourceDocument->series ?? ''),
                'numero' => (string) ($sourceDocument->number ?? ''),
            ] : null,
            'detalle' => $detalle,
            'letra' => $this->numberToLetras((int) round((float) ($document->total ?? 0)), (string) ($document->currency_name ?? 'SOLES')),
        ];
    }

    private function decodeJsonObject($value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function resolveTaxCategoryMap(int $companyId): array
    {
        $sourceTable = null;
        foreach (['core.tax_categories', 'sales.tax_categories', 'appcfg.tax_categories'] as $candidate) {
            if ($this->tableExists($candidate)) {
                $sourceTable = $candidate;
                break;
            }
        }

        if ($sourceTable === null) {
            return [];
        }

        $columns = $this->tableColumns($sourceTable);
        $idColumn = $this->firstExistingColumn($columns, ['id']);
        $codeColumn = $this->firstExistingColumn($columns, ['code', 'sunat_code', 'tax_code']);
        $labelColumn = $this->firstExistingColumn($columns, ['name', 'label', 'description']);
        $rateColumn = $this->firstExistingColumn($columns, ['rate_percent', 'rate', 'percentage', 'tax_rate']);
        $statusColumn = $this->firstExistingColumn($columns, ['status', 'is_enabled', 'enabled', 'active']);
        $companyColumn = $this->firstExistingColumn($columns, ['company_id']);

        if ($idColumn === null) {
            return [];
        }

        $query = DB::table($sourceTable);

        if ($statusColumn) {
            if ($statusColumn === 'status') {
                $query->where($statusColumn, 1);
            } else {
                $query->where($statusColumn, true);
            }
        }

        if ($companyColumn) {
            $query->where(function ($nested) use ($companyColumn, $companyId) {
                $nested->where($companyColumn, $companyId)
                    ->orWhereNull($companyColumn);
            });
        }

        $rows = $query->get();
        $normalized = [];
        foreach ($rows as $row) {
            $id = (int) ($row->{$idColumn} ?? 0);
            if ($id <= 0) {
                continue;
            }

            $normalized[$id] = [
                'id' => $id,
                'code' => strtoupper(trim((string) ($codeColumn ? ($row->{$codeColumn} ?? '') : ''))),
                'label' => strtoupper(trim((string) ($labelColumn ? ($row->{$labelColumn} ?? '') : ''))),
                'rate_percent' => $rateColumn ? (float) ($row->{$rateColumn} ?? 0) : 0.0,
            ];
        }

        return $normalized;
    }

    private function resolveLineTipoIgv(array $lineMeta, ?array $taxCategory, float $lineTax): string
    {
        $taxCode = strtoupper(trim((string) ($taxCategory['code'] ?? '')));
        $taxLabel = strtoupper(trim((string) ($taxCategory['label'] ?? '')));

        if (preg_match('/^2\d$/', $taxCode) === 1 || in_array($taxCode, ['9997', 'EXONERADA'], true) || strpos($taxLabel, 'EXONER') !== false) {
            return '20';
        }

        if (preg_match('/^3\d$/', $taxCode) === 1 || in_array($taxCode, ['9998', 'INAFECTA'], true) || strpos($taxLabel, 'INAFECT') !== false) {
            return '30';
        }

        if (preg_match('/^1\d$/', $taxCode) === 1 || in_array($taxCode, ['1000', 'IGV', 'VAT', 'GRAVADA'], true) || strpos($taxLabel, 'GRAV') !== false || strpos($taxLabel, 'IGV') !== false) {
            return '10';
        }

        $metaTipoIgv = trim((string) ($lineMeta['tipo_igv'] ?? ''));
        if ($metaTipoIgv !== '') {
            return $metaTipoIgv;
        }

        return $lineTax > 0.00001 ? '10' : '20';
    }

    private function resolveLineIgvPercent(string $tipoIgv, float $lineSubtotal, float $lineTax, ?array $taxCategory, float $configuredIgvRate): float
    {
        if (preg_match('/^[23]\d$/', $tipoIgv) === 1) {
            return 0.0;
        }

        if ($lineSubtotal > 0.00001 && $lineTax > 0.00001) {
            return round(($lineTax / $lineSubtotal) * 100, 2);
        }

        $categoryRate = $taxCategory !== null ? (float) ($taxCategory['rate_percent'] ?? 0) : 0.0;
        if ($categoryRate > 0.00001) {
            return round($categoryRate, 2);
        }

        return round($configuredIgvRate, 2);
    }

    private function tableExists(string $tableName): bool
    {
        [$schema, $table] = array_pad(explode('.', $tableName, 2), 2, null);
        if ($schema === null || $table === null) {
            return false;
        }

        return DB::table('information_schema.tables')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->exists();
    }

    private function tableColumns(string $tableName): array
    {
        [$schema, $table] = array_pad(explode('.', $tableName, 2), 2, null);
        if ($schema === null || $table === null) {
            return [];
        }

        return DB::table('information_schema.columns')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->orderBy('ordinal_position')
            ->pluck('column_name')
            ->map(function ($column) {
                return (string) $column;
            })
            ->all();
    }

    private function firstExistingColumn(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    private function legacyDocumentCode(string $documentKind): string
    {
        return match ($this->resolveDocumentBaseKind($documentKind, null)) {
            'INVOICE' => '01',
            'RECEIPT' => '03',
            'CREDIT_NOTE' => '07',
            'DEBIT_NOTE' => '08',
            default => '',
        };
    }

    private function resolveDocumentBaseKind(string $documentKind, ?int $documentKindId): string
    {
        if ($documentKindId !== null && $documentKindId > 0) {
            $catalogCode = DB::table('sales.document_kinds')
                ->where('id', $documentKindId)
                ->value('code');

            if (is_string($catalogCode) && trim($catalogCode) !== '') {
                $documentKind = $catalogCode;
            }
        }

        $normalized = strtoupper(trim($documentKind));

        if ($normalized === 'CREDIT_NOTE' || str_starts_with($normalized, 'CREDIT_NOTE_')) {
            return 'CREDIT_NOTE';
        }

        if ($normalized === 'DEBIT_NOTE' || str_starts_with($normalized, 'DEBIT_NOTE_')) {
            return 'DEBIT_NOTE';
        }

        return $normalized;
    }

    private function numberToLetras(int $numero, string $moneda = 'SOLES'): string
    {
        $unidades = ['', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve'];
        $decenas = ['', '', 'veinte', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa'];
        $centenas = ['', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos', 'seiscientos', 'setecientos', 'ochocientos', 'novecientos'];
        $especiales = ['diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve'];

        if ($numero === 0) {
            return "Cero con 00/100 $moneda";
        }

        $partes = [];
        $miles = intdiv($numero, 1000);
        $resto = $numero % 1000;

        if ($miles > 0) {
            if ($miles === 1) {
                $partes[] = 'mil';
            } else {
                $partes[] = $this->convertirGrupo($miles, $unidades, $decenas, $centenas, $especiales) . ' mil';
            }
        }

        if ($resto > 0) {
            $partes[] = $this->convertirGrupo($resto, $unidades, $decenas, $centenas, $especiales);
        }

        $letras = implode(' ', $partes);
        $letras = ucfirst(strtolower($letras));

        return "$letras con 00/100 $moneda";
    }

    private function resolveCustomerDocTypeCode(string $docType): int
    {
        if ($docType === '' || $docType === '0') {
            return 6;
        }

        $docTypeTrimmed = trim($docType);

        if (is_numeric($docTypeTrimmed)) {
            $typeByCode = DB::table('sales.customer_types')
                ->where('sunat_code', (int) $docTypeTrimmed)
                ->where('is_active', true)
                ->select('sunat_code')
                ->first();

            if ($typeByCode) {
                return (int) $typeByCode->sunat_code;
            }
        }

        $docTypeUpper = strtoupper($docTypeTrimmed);

        $type = DB::table('sales.customer_types')
            ->where('is_active', true)
            ->where(function ($query) use ($docTypeUpper, $docTypeTrimmed) {
                $query->whereRaw('UPPER(name) = ?', [$docTypeUpper])
                    ->orWhereRaw('UPPER(sunat_abbr) = ?', [$docTypeUpper])
                    ->orWhereRaw('UPPER(name) = ?', [strtoupper(str_replace('_', ' ', $docTypeTrimmed))]);
            })
            ->select('sunat_code')
            ->first();

        if ($type) {
            return (int) $type->sunat_code;
        }

        return 6;
    }

    private function convertirGrupo(int $numero, array $unidades, array $decenas, array $centenas, array $especiales): string
    {
        $partes = [];

        $c = intdiv($numero, 100);
        $resto = $numero % 100;

        if ($c > 0) {
            $partes[] = $centenas[$c];
        }

        if ($resto >= 10 && $resto <= 19) {
            $partes[] = $especiales[$resto - 10];
        } else {
            $d = intdiv($resto, 10);
            $u = $resto % 10;

            if ($d > 0) {
                $partes[] = $decenas[$d];
            }

            if ($u > 0) {
                $partes[] = $unidades[$u];
            }
        }

        return implode(' ', $partes);
    }
}
