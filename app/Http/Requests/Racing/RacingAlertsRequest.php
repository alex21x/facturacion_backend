<?php

namespace App\Http\Requests\Racing;

use App\Http\Requests\Api\ApiFirstErrorFormRequest;

class RacingAlertsRequest extends ApiFirstErrorFormRequest
{
    public function rules(): array
    {
        return [
            'maintenance_window_days' => 'nullable|integer|min:1|max:60',
        ];
    }
}
