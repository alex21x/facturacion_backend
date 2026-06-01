<?php

namespace App\Http\Requests\Cash;

use App\Http\Requests\Api\ApiSpanishValidationFormRequest;

class UpdateCashMovementRequest extends ApiSpanishValidationFormRequest
{
    public function rules(): array
    {
        return [
            'movement_type' => 'required|string|in:IN,OUT,INCOME,EXPENSE,ADJUSTMENT',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string|max:300',
        ];
    }
}
