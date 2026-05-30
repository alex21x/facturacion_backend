<?php

namespace App\Http\Requests\Restaurant;

use App\Http\Requests\Api\ApiFormRequest;

class UpdateRestaurantTableRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'nullable|string|max:120',
            'capacity' => 'nullable|integer|min:1|max:30',
            'status' => 'nullable|string|in:AVAILABLE,OCCUPIED,RESERVED,DISABLED',
        ];
    }
}