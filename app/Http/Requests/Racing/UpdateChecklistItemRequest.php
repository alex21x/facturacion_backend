<?php

namespace App\Http\Requests\Racing;

use App\Http\Requests\Api\ApiFormRequest;

class UpdateChecklistItemRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'loaded_qty' => 'nullable|numeric|min:0',
            'returned_qty' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|max:20',
            'notes' => 'nullable|string|max:5000',
        ];
    }
}
