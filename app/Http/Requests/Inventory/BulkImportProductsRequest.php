<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\Api\ApiFormRequest;

class BulkImportProductsRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'rows' => 'required|array|min:1|max:5000',
            'rows.*.id' => 'nullable|integer|min:1',
            'rows.*.sku' => 'nullable|string|max:60',
            'rows.*.barcode' => 'nullable|string|max:80',
            'rows.*.name' => 'nullable|string|max:180',
            'rows.*.product_nature' => 'nullable|string|max:30',
            'rows.*.sale_price' => 'nullable',
            'rows.*.cost_price' => 'nullable',
            'rows.*.unit_code' => 'nullable|string|max:40',
            'rows.*.sunat_code' => 'nullable|string|max:40',
            'rows.*.is_stockable' => 'nullable',
            'rows.*.lot_tracking' => 'nullable',
            'rows.*.has_expiration' => 'nullable',
            'rows.*.status' => 'nullable',
            'rows.*.initial_qty' => 'nullable',
            'rows.*.initial_cost' => 'nullable',
            'rows.*.warehouse_code' => 'nullable|string|max:50',
            'warehouse_code' => 'nullable|string|max:50',
            'filename' => 'nullable|string|max:300',
        ];
    }
}