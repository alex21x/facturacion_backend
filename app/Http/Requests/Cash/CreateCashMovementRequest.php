<?php

namespace App\Http\Requests\Cash;

use App\Http\Requests\Api\ApiSpanishValidationFormRequest;

class CreateCashMovementRequest extends ApiSpanishValidationFormRequest
{
    public function rules(): array
    {
        return [
            'company_id' => 'nullable|integer|min:1',
            'cash_register_id' => 'required|integer|min:1',
            'cash_session_id' => 'nullable|integer|min:1',
            'movement_type' => 'required|string|in:IN,OUT,INCOME,EXPENSE,ADJUSTMENT',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string|max:300',
        ];
    }
}