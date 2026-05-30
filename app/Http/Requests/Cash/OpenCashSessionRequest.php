<?php

namespace App\Http\Requests\Cash;

use App\Http\Requests\Api\ApiSpanishValidationFormRequest;

class OpenCashSessionRequest extends ApiSpanishValidationFormRequest
{
    public function rules(): array
    {
        return [
            'company_id' => 'nullable|integer|min:1',
            'cash_register_id' => 'required|integer|min:1',
            'opening_balance' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ];
    }
}