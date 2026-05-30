<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\Api\ApiFormRequest;

class UpdateProductCommercialConfigRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'company_id' => 'nullable|integer|min:1',
            'base_unit_id' => 'nullable|integer|min:1',
            'units' => 'nullable|array',
            'units.*.unit_id' => 'required_with:units|integer|min:1',
            'units.*.is_base' => 'nullable|boolean',
            'units.*.status' => 'nullable|integer|in:0,1',
            'conversions' => 'nullable|array',
            'conversions.*.from_unit_id' => 'required_with:conversions|integer|min:1',
            'conversions.*.to_unit_id' => 'required_with:conversions|integer|min:1',
            'conversions.*.conversion_factor' => 'required_with:conversions|numeric|min:0.00000001',
            'conversions.*.status' => 'nullable|integer|in:0,1',
            'wholesale_prices' => 'nullable|array',
            'wholesale_prices.*.price_tier_id' => 'required_with:wholesale_prices|integer|min:1',
            'wholesale_prices.*.unit_id' => 'nullable|integer|min:1',
            'wholesale_prices.*.unit_price' => 'required_with:wholesale_prices|numeric|min:0',
            'wholesale_prices.*.status' => 'nullable|integer|in:0,1',
        ];
    }
}