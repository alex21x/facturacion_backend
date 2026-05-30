<?php

namespace App\Http\Requests\Sales;

use App\Http\Requests\Api\ApiFirstErrorFallbackFormRequest;
use App\Services\Sales\SalesLookupService;
use Illuminate\Support\Carbon;

abstract class SalesDocumentKindFormRequest extends ApiFirstErrorFallbackFormRequest
{
    protected function prepareForValidation(): void
    {
        $payload = $this->all();

        $payload['issue_at'] = $this->normalizeDateInput($payload['issue_at'] ?? null);
        $payload['due_at'] = $this->normalizeDateInput($payload['due_at'] ?? null);

        if (isset($payload['payments']) && is_array($payload['payments'])) {
            foreach ($payload['payments'] as $index => $payment) {
                if (!is_array($payment)) {
                    continue;
                }

                $payload['payments'][$index]['due_at'] = $this->normalizeDateInput($payment['due_at'] ?? null);
                $payload['payments'][$index]['paid_at'] = $this->normalizeDateInput($payment['paid_at'] ?? null);
            }
        }

        $this->replace($payload);
    }

    protected function documentKindCodes(): array
    {
        $rows = collect(app(SalesLookupService::class)->listDocumentKindsCatalog());

        if (!$rows->isEmpty()) {
            return $rows
                ->map(static fn ($row) => (string) data_get($row, 'code', ''))
                ->filter(static fn (string $code) => $code !== '')
                ->values()
                ->all();
        }

        return [
            'QUOTATION',
            'SALES_ORDER',
            'INVOICE',
            'RECEIPT',
            'CREDIT_NOTE',
            'DEBIT_NOTE',
        ];
    }

    protected function normalizeDateInput(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (!is_string($value)) {
            if (is_array($value)) {
                foreach (['value', 'date', 'datetime', 'raw'] as $key) {
                    if (array_key_exists($key, $value)) {
                        return $this->normalizeDateInput($value[$key]);
                    }
                }
            }

            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $lower = strtolower($trimmed);
        if (in_array($lower, ['invalid date', 'undefined', 'null', 'nan'], true)) {
            return null;
        }

        $normalized = str_replace(',', '', $trimmed);

        foreach (['d/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'd-m-Y H:i:s', 'd-m-Y H:i', 'd-m-Y'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $normalized);
            if ($parsed !== false) {
                return $parsed->format('Y-m-d H:i:s');
            }
        }

        try {
            return Carbon::parse($normalized)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }
}