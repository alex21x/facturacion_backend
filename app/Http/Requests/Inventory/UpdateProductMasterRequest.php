<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\Api\ApiFormRequest;

class UpdateProductMasterRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'kind' => 'required|string|in:line,brand,location,warranty',
            'name' => 'nullable|string|max:120',
            'status' => 'nullable|integer|in:0,1',
        ];
    }
}