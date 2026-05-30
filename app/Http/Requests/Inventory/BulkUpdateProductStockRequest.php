<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\Api\ApiFormRequest;

class BulkUpdateProductStockRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'rows' => 'required|array|min:1|max:5000',
            'rows.*.id' => 'nullable|integer|min:1',
            'rows.*.sku' => 'nullable|string|max:60',
            'rows.*.warehouse_code' => 'nullable|string|max:50',
            'rows.*.qty' => 'required|numeric',
            'rows.*.note' => 'nullable|string|max:300',
            'rows.*.metadata' => 'nullable|array',
            'mode' => 'required|string|in:add,replace',
            'warehouse_code' => 'nullable|string|max:50',
            'filename' => 'nullable|string|max:300',
        ];
    }
}