<?php

namespace App\Http\Requests\Racing;

use App\Http\Requests\Api\ApiFormRequest;

class CreateMaintenanceRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'component_code' => 'required|string|max:80',
            'action_type' => 'required|string|max:30',
            'service_date' => 'required|date',
            'next_service_date' => 'nullable|date',
            'odometer_km' => 'nullable|numeric|min:0',
            'estimated_life_km' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:5000',
        ];
    }
}
