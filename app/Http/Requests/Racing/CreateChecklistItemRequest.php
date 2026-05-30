<?php

namespace App\Http\Requests\Racing;

use App\Http\Requests\Api\ApiFormRequest;

class CreateChecklistItemRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'item_type' => 'nullable|string|max:30',
            'item_label' => 'required|string|max:180',
            'planned_qty' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:5000',
        ];
    }
}
