<?php

namespace App\Http\Requests\Sales;

use App\Http\Requests\Api\ApiFirstErrorFallbackFormRequest;

class PrintableCommercialDocumentRequest extends ApiFirstErrorFallbackFormRequest
{
    protected function prepareForValidation(): void
    {
        $format = $this->query('format', 'ticket');

        if (!is_string($format)) {
            $format = 'ticket';
        }

        $format = strtolower(trim($format));
        if ($format === '') {
            $format = 'ticket';
        }

        $this->merge(['format' => $format]);
    }

    public function rules(): array
    {
        return [
            'format' => 'required|string|in:ticket,a4',
        ];
    }
}
