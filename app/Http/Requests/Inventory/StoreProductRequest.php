<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\Api\ApiFormRequest;

class StoreProductRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'category_id' => 'nullable|integer|min:1',
            'unit_id' => 'nullable|integer|min:1',
            'line_id' => 'nullable|integer|min:1',
            'brand_id' => 'nullable|integer|min:1',
            'location_id' => 'nullable|integer|min:1',
            'warranty_id' => 'nullable|integer|min:1',
            'product_nature' => 'nullable|string|in:PRODUCT,SUPPLY',
            'sku' => 'nullable|string|max:60',
            'barcode' => 'nullable|string|max:80',
            'sunat_code' => 'nullable|string|max:40',
            'image_url' => 'nullable|string|max:500',
            'seller_commission_percent' => 'nullable|numeric|min:0|max:100',
            'name' => 'required|string|max:180',
            'sale_price' => 'nullable|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'is_stockable' => 'nullable|boolean',
            'lot_tracking' => 'nullable|boolean',
            'has_expiration' => 'nullable|boolean',
            'status' => 'nullable|integer|in:0,1',
            'initial_qty' => 'nullable|numeric|min:0',
            'initial_cost' => 'nullable|numeric|min:0',
            'warehouse_id' => 'nullable|integer|min:1',
            'warehouse_code' => 'nullable|string|max:80',
            'stock_note' => 'nullable|string|max:255',
        ];
    }
}