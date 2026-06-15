<?php


namespace App\Services\Sales;

use App\Application\UseCases\Sales\ResolveCompanyPrintProfileUseCase;
use App\Contracts\Sales\SalesDocumentApplicationServiceInterface;
use App\Services\Sales\Documents\SalesDocumentException;
use App\Services\Sales\Documents\SalesDocumentReadService;
use Illuminate\Support\Carbon;

class SalesDocumentApplicationService implements SalesDocumentApplicationServiceInterface
{
    public function __construct(
        private SalesDocumentReadService $salesDocumentReadService,
        private SalesLookupService $salesLookupService,
        private ResolveCompanyPrintProfileUseCase $resolveCompanyPrintProfileUseCase
    ) {
    }

    public function buildPrintableCommercialDocumentHtml(int $companyId, int $documentId, string $format = 'ticket'): string
    {
        $doc = $this->salesDocumentReadService->findDocumentForShow($companyId, $documentId);

        if (!$doc) {
            throw new SalesDocumentException('Documento no encontrado', 404);
        }

        $items = $this->salesDocumentReadService->resolveDocumentItemsWithFallback($companyId, $documentId);

        $companyProfile = $this->resolveCompanyPrintProfileUseCase->execute($companyId);
        $companyName = trim((string) (
            $companyProfile['trade_name']
            ?? $companyProfile['legal_name']
            ?? 'EMPRESA'
        ));
        $companyTaxId = trim((string) ($companyProfile['tax_id'] ?? ''));

        $normalizedFormat = in_array($format, ['ticket', 'a4'], true) ? $format : 'ticket';

        $metadata = [];
        if ($doc->metadata !== null && trim((string) $doc->metadata) !== '') {
            $decoded = json_decode((string) $doc->metadata, true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }

        $documentKindRaw = strtoupper(trim((string) ($doc->document_kind ?? 'DOCUMENTO')));
        $documentKindLabel = [
            'INVOICE' => 'FACTURA ELECTRONICA',
            'RECEIPT' => 'BOLETA DE VENTA ELECTRONICA',
            'CREDIT_NOTE' => 'NOTA DE CREDITO',
            'DEBIT_NOTE' => 'NOTA DE DEBITO',
            'SALES_ORDER' => 'PEDIDO DE VENTA',
            'QUOTATION' => 'COTIZACION',
        ][$documentKindRaw] ?? ($documentKindRaw !== '' ? $documentKindRaw : 'DOCUMENTO');

        $issueDate = $this->formatDisplayDate((string) ($doc->issue_at ?? ''));
        $dueDate = $this->formatDisplayDate((string) ($doc->due_at ?? ''));
        $guideNo = trim((string) (
            $metadata['guia']
            ?? $metadata['nro_guia']
            ?? $metadata['guide_number']
            ?? $metadata['guideNumber']
            ?? ''
        ));

        $electronicSignature = $this->normalizeElectronicSignatureValue($this->findFirstMetaStringValue($metadata, [
            'sunat_electronic_signature',
            'sunat_signature',
            'firma_electronica',
            'firma',
            'signature',
            'hash_cpe',
            'codigo_hash',
            'digest_value',
            'digestValue',
        ]));

        $paymentBreakdown = [];
        $paymentRows = $metadata['payment_breakdown'] ?? null;
        if (is_array($paymentRows)) {
            foreach ($paymentRows as $paymentRow) {
                if (!is_array($paymentRow)) {
                    continue;
                }

                $amount = (float) ($paymentRow['amount'] ?? 0);
                if ($amount <= 0) {
                    continue;
                }

                $paymentBreakdown[] = [
                    'method' => trim((string) (
                        $paymentRow['payment_method_name']
                        ?? $paymentRow['method_name']
                        ?? $paymentRow['name']
                        ?? 'Metodo de pago'
                    )),
                    'amount' => number_format($amount, 2, '.', ''),
                ];
            }
        }

        $rows = [];
        foreach ($items as $item) {
            $itemMeta = [];
            $itemMetadataRaw = data_get($item, 'metadata');
            if ($itemMetadataRaw !== null && trim((string) $itemMetadataRaw) !== '') {
                $itemDecoded = json_decode((string) $itemMetadataRaw, true);
                if (is_array($itemDecoded)) {
                    $itemMeta = $itemDecoded;
                }
            }

            $productCode = trim((string) (
                data_get($item, 'product_code')
                ?? $itemMeta['product_code']
                ?? $itemMeta['productCode']
                ?? $itemMeta['code']
                ?? ''
            ));

            $rows[] = [
                'line_no' => (int) (data_get($item, 'line_no') ?? 0),
                'description' => (string) (data_get($item, 'description') ?? '-'),
                'product_code' => $productCode,
                'unit_label' => (string) (data_get($item, 'unit_code') ?? 'NIU'),
                'qty' => number_format((float) (data_get($item, 'qty') ?? 0), 2, '.', ''),
                'unit_price' => number_format((float) (data_get($item, 'unit_price') ?? 0), 2, '.', ''),
                'total' => number_format((float) (data_get($item, 'total') ?? 0), 2, '.', ''),
            ];
        }

        $subtotal = (float) ($doc->subtotal ?? 0);
        $taxTotal = (float) ($doc->tax_total ?? 0);
        $grandTotal = (float) ($doc->total ?? 0);
        $customerPhone = trim((string) ($doc->customer_phone ?? ''));
        $vehicleInfo = trim(implode(' ', array_filter([
            trim((string) ($doc->vehicle_plate_snapshot ?? '')),
            trim((string) ($doc->vehicle_brand_snapshot ?? '')),
            trim((string) ($doc->vehicle_model_snapshot ?? '')),
        ], static fn ($value) => $value !== '')));
        $totalWords = $this->amountToSpanishWords($grandTotal, (string) ($doc->currency_code ?? 'PEN'));

        return view('sales.documents.printable_commercial_document', [
            'format' => $normalizedFormat,
            'companyName' => $companyName,
            'companyTaxId' => $companyTaxId,
            'companyAddress' => (string) ($companyProfile['address'] ?? ''),
            'companyPhone' => (string) ($companyProfile['phone'] ?? ''),
            'companyEmail' => (string) ($companyProfile['email'] ?? ''),
            'companyDescription' => (string) ($companyProfile['company_description'] ?? ''),
            'companyLogo' => (string) ($companyProfile['logo_data_uri'] ?? $companyProfile['logo_url'] ?? ''),
            'companyBankAccounts' => is_array($companyProfile['bank_accounts'] ?? null)
                ? $companyProfile['bank_accounts']
                : [],
            'documentKindLabel' => $documentKindLabel,
            'series' => (string) ($doc->series ?? ''),
            'number' => (string) ($doc->number ?? ''),
            'issueDate' => $issueDate,
            'issueDateOnly' => $this->formatDisplayDateOnly((string) ($doc->issue_at ?? '')),
            'dueDate' => $dueDate,
            'customer' => (string) ($doc->customer_name ?? '-'),
            'customerDoc' => (string) ($doc->customer_doc_number ?? '-'),
            'customerAddress' => (string) ($doc->customer_address ?? '-'),
            'customerPhone' => $customerPhone,
            'vehicleInfo' => $vehicleInfo,
            'documentNotes' => trim((string) ($doc->notes ?? '')),
            'paymentMethod' => (string) ($doc->payment_method_name ?? '-'),
            'paymentBreakdown' => $paymentBreakdown,
            'guideNo' => $guideNo,
            'electronicSignature' => $electronicSignature,
            'currency' => (string) ($doc->currency_symbol ?? 'S/'),
            'currencyCode' => (string) ($doc->currency_code ?? 'PEN'),
            'subtotal' => number_format($subtotal, 2, '.', ''),
            'taxTotal' => number_format($taxTotal, 2, '.', ''),
            'grandTotal' => number_format($grandTotal, 2, '.', ''),
            'total' => number_format((float) ($doc->total ?? 0), 2, '.', ''),
            'totalWords' => $totalWords,
            'rows' => $rows,
        ])->render();
    }


    private function findFirstMetaStringValue(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            $candidate = $source[$key] ?? null;
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        foreach ($source as $value) {
            if (is_array($value)) {
                $nested = $this->findFirstMetaStringValue($value, $keys);
                if ($nested !== '') {
                    return $nested;
                }
            }
        }

        return '';
    }

    private function normalizeElectronicSignatureValue(string $raw): string
    {
        $value = trim($raw);
        if ($value === '') {
            return '';
        }

        if ((str_starts_with($value, '{') && str_ends_with($value, '}')) || (str_starts_with($value, '[') && str_ends_with($value, ']'))) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $first = $this->extractFirstScalarValue($decoded);
                if ($first !== '') {
                    return $first;
                }
            }
        }

        return $value;
    }

    private function extractFirstScalarValue(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            foreach ($value as $nested) {
                $scalar = $this->extractFirstScalarValue($nested);
                if ($scalar !== '') {
                    return $scalar;
                }
            }
        }

        return '';
    }

    private function formatDisplayDate(string $raw): string
    {
        $value = trim($raw);
        if ($value === '') {
            return '';
        }

        try {
            return Carbon::parse($value)->format('d/m/Y H:i:s');
        } catch (\Throwable $e) {
            return $value;
        }
    }

    private function formatDisplayDateOnly(string $raw): string
    {
        $value = trim($raw);
        if ($value === '') {
            return '';
        }

        try {
            return Carbon::parse($value)->format('d/m/Y');
        } catch (\Throwable $e) {
            return $value;
        }
    }

    private function amountToSpanishWords(float $amount, string $currencyCode = 'PEN'): string
    {
        $safeAmount = max(0, $amount);
        $integerPart = (int) floor($safeAmount);
        $decimalPart = (int) round(($safeAmount - $integerPart) * 100);

        if ($decimalPart >= 100) {
            $integerPart += 1;
            $decimalPart = 0;
        }

        $currencyName = strtoupper(trim($currencyCode)) === 'USD' ? 'DOLARES' : 'SOLES';
        $decimalText = str_pad((string) $decimalPart, 2, '0', STR_PAD_LEFT);
        $words = strtoupper($this->numberToSpanishWords($integerPart));

        return $words . ' CON ' . $decimalText . '/100 ' . $currencyName;
    }

    private function numberToSpanishWords(int $number): string
    {
        if ($number === 0) {
            return 'cero';
        }

        $millions = intdiv($number, 1000000);
        $thousands = intdiv($number % 1000000, 1000);
        $hundreds = $number % 1000;
        $parts = [];

        if ($millions > 0) {
            if ($millions === 1) {
                $parts[] = 'un millon';
            } else {
                $parts[] = $this->numberToSpanishWords($millions) . ' millones';
            }
        }

        if ($thousands > 0) {
            if ($thousands === 1) {
                $parts[] = 'mil';
            } else {
                $parts[] = $this->convertThreeDigitsToSpanishWords($thousands) . ' mil';
            }
        }

        if ($hundreds > 0) {
            $parts[] = $this->convertThreeDigitsToSpanishWords($hundreds);
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)) ?? '');
    }

    private function convertThreeDigitsToSpanishWords(int $number): string
    {
        $units = ['', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve'];
        $teens = ['diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciseis', 'diecisiete', 'dieciocho', 'diecinueve'];
        $tens = ['', '', 'veinte', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa'];
        $hundreds = ['', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos', 'seiscientos', 'setecientos', 'ochocientos', 'novecientos'];

        if ($number === 0) {
            return '';
        }

        if ($number === 100) {
            return 'cien';
        }

        $c = intdiv($number, 100);
        $rest = $number % 100;
        $parts = [];

        if ($c > 0) {
            $parts[] = $hundreds[$c];
        }

        if ($rest >= 10 && $rest <= 19) {
            $parts[] = $teens[$rest - 10];
        } else {
            $d = intdiv($rest, 10);
            $u = $rest % 10;

            if ($d === 2 && $u > 0) {
                $parts[] = 'veinti' . $units[$u];
            } else {
                if ($d > 0) {
                    $parts[] = $tens[$d];
                }

                if ($u > 0) {
                    if ($d > 2) {
                        $parts[] = 'y ' . $units[$u];
                    } elseif ($d === 0) {
                        $parts[] = $units[$u];
                    }
                }
            }
        }

        return trim(implode(' ', $parts));
    }
}


