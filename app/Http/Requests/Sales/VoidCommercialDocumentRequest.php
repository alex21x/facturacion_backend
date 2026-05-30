<?php

namespace App\Http\Requests\Sales;

use App\Http\Requests\Api\ApiFirstErrorFallbackFormRequest;

class VoidCommercialDocumentRequest extends ApiFirstErrorFallbackFormRequest
{
    public function rules(): array
    {
        return [
            'reason' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:500',
            'void_at' => 'nullable|date',
            'sunat_void_status' => 'nullable|string|max:40',
            'void_password' => 'nullable|string|max:120',
        ];
    }
}