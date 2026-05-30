<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\Api\ApiFormRequest;

class StoreProductMasterRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'kind' => 'required|string|in:line,brand,location,warranty',
            'name' => 'required|string|max:120',
        ];
    }
}