<?php

namespace App\Http\Requests\Cash;

use App\Http\Requests\Api\ApiSpanishValidationFormRequest;

class CloseCashSessionRequest extends ApiSpanishValidationFormRequest
{
    public function rules(): array
    {
        return [
            'closing_balance' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ];
    }
}