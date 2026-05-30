<?php

namespace App\Http\Requests\Racing;

use App\Http\Requests\Api\ApiFormRequest;

class CreateEventRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'code' => 'required|string|max:40',
            'name' => 'required|string|max:160',
            'location' => 'nullable|string|max:120',
            'starts_at' => 'required|date',
            'ends_at' => 'nullable|date',
            'budget_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:5000',
        ];
    }
}
