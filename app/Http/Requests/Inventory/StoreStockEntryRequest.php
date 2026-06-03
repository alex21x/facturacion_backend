<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\Api\ApiFormRequest;

class StoreStockEntryRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'company_id' => 'nullable|integer|min:1',
            'branch_id' => 'nullable|integer|min:1',
            'warehouse_id' => 'required|integer|min:1',
            'entry_type' => 'required|string|in:PURCHASE,ADJUSTMENT,PURCHASE_ORDER,NON_TAX_IN,NON_TAX_OUT',
            'reference_no' => 'required_if:entry_type,PURCHASE,PURCHASE_ORDER,NON_TAX_IN,NON_TAX_OUT|string|max:60',
            'supplier_reference' => 'required_if:entry_type,PURCHASE,PURCHASE_ORDER,NON_TAX_IN,NON_TAX_OUT|string|max:120',
            'payment_method_id' => 'nullable|integer|min:1',
            'issue_at' => 'nullable|date',
            'notes' => 'required_if:entry_type,ADJUSTMENT|nullable|string|max:300',
            'metadata' => 'nullable|array',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|min:1',
            'items.*.qty' => 'required|numeric',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.lot_id' => 'nullable|integer|min:1',
            'items.*.lot_code' => 'nullable|string|max:80',
            'items.*.manufacture_at' => 'nullable|date',
            'items.*.expires_at' => 'nullable|date',
            'items.*.tax_category_id' => 'nullable|integer|min:1',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.notes' => 'nullable|string|max:200',
            'items.*.metadata' => 'nullable|array',
        ];
    }
}