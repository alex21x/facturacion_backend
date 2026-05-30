<?php

namespace App\Http\Requests\Racing;

use App\Http\Requests\Api\ApiFirstErrorFormRequest;

class RacingVehiclesRequest extends ApiFirstErrorFormRequest
{
    public function rules(): array
    {
        return [
            'q' => 'nullable|string|max:120',
        ];
    }
}
