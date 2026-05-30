<?php

namespace App\Http\Requests\Sales;

use App\Http\Requests\Api\ApiFirstErrorFallbackFormRequest;
use Illuminate\Support\Carbon;

class ConvertCommercialDocumentRequest extends ApiFirstErrorFallbackFormRequest
{
    protected function prepareForValidation(): void
    {
        $payload = $this->all();

        $payload['issue_at'] = $this->normalizeDateInput($payload['issue_at'] ?? null);
        $payload['due_at'] = $this->normalizeDateInput($payload['due_at'] ?? null);

        $this->replace($payload);
    }

    public function rules(): array
    {
        return [
            'target_document_kind' => 'required|string|in:INVOICE,RECEIPT,SALES_ORDER',
            'series' => 'nullable|string|max:10',
            'issue_at' => 'nullable|date',
            'due_at' => 'nullable|date',
            'cash_register_id' => 'nullable|integer|min:1',
            'payment_method_id' => 'nullable|integer|min:1',
            'defer_sunat_send' => 'nullable|boolean',
            'notes' => 'nullable|string',
            'status' => 'nullable|string|in:ISSUED,DRAFT',
        ];
    }

    private function normalizeDateInput(mixed $value): mixed
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