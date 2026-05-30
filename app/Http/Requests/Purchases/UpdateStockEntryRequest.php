<?php

namespace App\Http\Requests\Purchases;

use App\Http\Requests\Api\ApiFormRequest;

class UpdateStockEntryRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'company_id' => 'nullable|integer|min:1',
            'reference_no' => 'nullable|string|max:60',
            'supplier_reference' => 'nullable|string|max:120',
            'payment_method_id' => 'nullable|integer|min:1',
            'issue_at' => 'nullable|date',
            'notes' => 'nullable|string|max:300',
            'metadata' => 'nullable|array',
            'edit_reason' => 'nullable|string|max:180',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|min:1',
            'items.*.qty' => 'required|numeric',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.tax_category_id' => 'nullable|integer|min:1',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.lot_id' => 'nullable|integer|min:1',
            'items.*.lot_code' => 'nullable|string|max:80',
            'items.*.manufacture_at' => 'nullable|date',
            'items.*.expires_at' => 'nullable|date',
            'items.*.notes' => 'nullable|string|max:200',
            'items.*.metadata' => 'nullable|array',
        ];
    }
}