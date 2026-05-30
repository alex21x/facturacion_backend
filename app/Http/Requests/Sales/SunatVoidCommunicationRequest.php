<?php

namespace App\Http\Requests\Sales;

use App\Http\Requests\Api\ApiFirstErrorFallbackFormRequest;

class SunatVoidCommunicationRequest extends ApiFirstErrorFallbackFormRequest
{
    public function rules(): array
    {
        return [
            'reason' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:500',
        ];
    }
}