<?php

namespace App\Http\Requests\Racing;

use App\Http\Requests\Api\ApiFormRequest;

class CreateEventCostRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'cost_stage' => 'required|string|in:PRE,DURING,POST',
            'cost_category' => 'required|string|max:40',
            'concept' => 'required|string|max:180',
            'amount' => 'required|numeric|min:0',
            'cost_date' => 'required|date',
            'supplier_name' => 'nullable|string|max:160',
            'notes' => 'nullable|string|max:5000',
        ];
    }
}
