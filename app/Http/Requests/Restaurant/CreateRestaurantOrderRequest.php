<?php

namespace App\Http\Requests\Restaurant;

use App\Http\Requests\Api\ApiFormRequest;

class CreateRestaurantOrderRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'branch_id' => 'required|integer|min:1',
            'warehouse_id' => 'nullable|integer|min:1',
            'table_id' => 'nullable|integer|min:1',
            'series' => 'required|string|max:10',
            'currency_id' => 'required|integer|min:1',
            'payment_method_id' => 'required|integer|min:1',
            'customer_id' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|integer|min:1',
            'items.*.description' => 'required|string|max:300',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.unit_id' => 'nullable|integer|min:1',
            'items.*.tax_type' => 'nullable|string|max:20',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.subtotal' => 'nullable|numeric|min:0',
            'items.*.tax_total' => 'nullable|numeric|min:0',
            'items.*.total' => 'nullable|numeric|min:0',
        ];
    }
}