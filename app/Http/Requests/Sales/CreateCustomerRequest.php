<?php

namespace App\Http\Requests\Sales;

use App\Http\Requests\Api\ApiFormRequest;
use App\Services\Sales\CustomerVehicleService;

class CreateCustomerRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'doc_type' => 'nullable|string|max:20',
            'customer_type_id' => [
                'required',
                'integer',
                function ($attribute, $value, $fail) {
                    if (!app(CustomerVehicleService::class)->customerTypeExists((int) $value)) {
                        $fail('El tipo de cliente seleccionado no es válido.');
                    }
                },
            ],
            'doc_number' => 'nullable|string|max:40',
            'legal_name' => 'nullable|string|max:180',
            'trade_name' => 'nullable|string|max:180',
            'first_name' => 'nullable|string|max:120',
            'last_name' => 'nullable|string|max:120',
            'plate' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:250',
            'phone' => 'nullable|string|max:40',
            'status' => 'nullable|integer|in:0,1',
            'default_tier_id' => 'nullable|integer|min:1',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'price_profile_status' => 'nullable|integer|in:0,1',
        ];
    }
}