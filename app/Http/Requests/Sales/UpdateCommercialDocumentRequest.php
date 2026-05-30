<?php

namespace App\Http\Requests\Sales;

use Illuminate\Validation\Rule;

class UpdateCommercialDocumentRequest extends SalesDocumentKindFormRequest
{
    public function rules(): array
    {
        return [
            'document_kind_id' => 'nullable|integer|min:1',
            'document_kind' => ['sometimes', 'string', Rule::in($this->documentKindCodes())],
            'branch_id' => 'nullable|integer|min:1',
            'warehouse_id' => 'nullable|integer|min:1',
            'cash_register_id' => 'nullable|integer|min:1',
            'due_at' => 'nullable|date',
            'customer_id' => 'nullable|integer|min:1',
            'currency_id' => 'nullable|integer|min:1',
            'payment_method_id' => 'nullable|integer|min:1',
            'notes' => 'nullable|string',
            'metadata' => 'nullable|array',
            'items' => 'nullable|array|min:1',
            'items.*.line_no' => 'nullable|integer|min:1',
            'items.*.product_id' => 'nullable|integer|min:1',
            'items.*.unit_id' => 'nullable|integer|min:1',
            'items.*.price_tier_id' => 'nullable|integer|min:1',
            'items.*.tax_category_id' => 'nullable|integer|min:1',
            'items.*.description' => 'required_with:items|string|max:500',
            'items.*.qty' => 'required_with:items|numeric|min:0.001',
            'items.*.qty_base' => 'nullable|numeric|min:0',
            'items.*.conversion_factor' => 'nullable|numeric|min:0.00000001',
            'items.*.base_unit_price' => 'nullable|numeric|min:0',
            'items.*.unit_price' => 'required_with:items|numeric|min:0',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.wholesale_discount_percent' => 'nullable|numeric|min:0',
            'items.*.price_source' => 'nullable|string|in:MANUAL,TIER,PROFILE',
            'items.*.discount_total' => 'nullable|numeric|min:0',
            'items.*.tax_total' => 'nullable|numeric|min:0',
            'items.*.subtotal' => 'nullable|numeric|min:0',
            'items.*.total' => 'nullable|numeric|min:0',
            'items.*.metadata' => 'nullable|array',
            'items.*.lots' => 'nullable|array',
            'items.*.lots.*.lot_id' => 'required_with:items.*.lots|integer|min:1',
            'items.*.lots.*.qty' => 'required_with:items.*.lots|numeric|min:0.001',
        ];
    }
}