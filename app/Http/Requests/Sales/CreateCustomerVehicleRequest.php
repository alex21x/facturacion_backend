<?php

namespace App\Http\Requests\Sales;

use App\Http\Requests\Api\ApiFormRequest;

class CreateCustomerVehicleRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'plate' => 'required|string|max:20',
            'brand' => 'nullable|string|max:80',
            'model' => 'nullable|string|max:80',
            'year' => 'nullable|integer|min:1900|max:2100',
            'color' => 'nullable|string|max:40',
            'vin' => 'nullable|string|max:50',
            'is_default' => 'nullable|boolean',
            'status' => 'nullable|integer|in:0,1',
        ];
    }
}