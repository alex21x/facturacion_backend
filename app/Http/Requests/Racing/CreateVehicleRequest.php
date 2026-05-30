<?php

namespace App\Http\Requests\Racing;

use App\Http\Requests\Api\ApiFormRequest;

class CreateVehicleRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'code' => 'required|string|max:40',
            'name' => 'required|string|max:120',
            'plate' => 'nullable|string|max:20',
            'model' => 'nullable|string|max:120',
        ];
    }
}
