<?php

namespace App\Http\Requests\Restaurant;

use App\Http\Requests\Api\ApiFormRequest;

class CreateRestaurantTableRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'branch_id' => 'required|integer|min:1',
            'code' => 'required|string|max:40',
            'name' => 'required|string|max:120',
            'capacity' => 'required|integer|min:1|max:30',
        ];
    }
}