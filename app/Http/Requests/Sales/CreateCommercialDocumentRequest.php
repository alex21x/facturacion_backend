<?php

namespace App\Http\Requests\Sales;

use Illuminate\Validation\Rule;

class CreateCommercialDocumentRequest extends SalesDocumentKindFormRequest
{
    public function rules(): array
    {
        return [
            'company_id' => 'nullable|integer|min:1',
            'branch_id' => 'nullable|integer|min:1',
            'warehouse_id' => 'nullable|integer|min:1',
            'cash_register_id' => 'nullable|integer|min:1',
            'document_kind_id' => 'nullable|integer|min:1',
            'document_kind' => ['required_without:document_kind_id', 'string', Rule::in($this->documentKindCodes())],
            'series' => 'required|string|max:10',
            'issue_at' => 'nullable|date',
            'due_at' => 'nullable|date',
            'customer_id' => 'required|integer|min:1',
            'customer_vehicle_id' => 'nullable|integer|min:1',
            'currency_id' => 'required|integer|min:1',
            'payment_method_id' => 'nullable|integer|min:1',
            'exchange_rate' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'metadata' => 'nullable|array',
            'status' => 'nullable|string|in:DRAFT,APPROVED,ISSUED,VOID,CANCELED',
            'items' => 'required|array|min:1',
            'items.*.line_no' => 'nullable|integer|min:1',
            'items.*.product_id' => 'nullable|integer|min:1',
            'items.*.unit_id' => 'nullable|integer|min:1',
            'items.*.price_tier_id' => 'nullable|integer|min:1',
            'items.*.tax_category_id' => 'nullable|integer|min:1',
            'items.*.description' => 'required|string|max:500',
            'items.*.qty' => 'required|numeric|min:0.001',
            'items.*.qty_base' => 'nullable|numeric|min:0',
            'items.*.conversion_factor' => 'nullable|numeric|min:0.00000001',
            'items.*.base_unit_price' => 'nullable|numeric|min:0',
            'items.*.unit_price' => 'required|numeric|min:0',
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
            'payments' => 'nullable|array',
            'payments.*.payment_method_id' => 'required_with:payments|integer|min:1',
            'payments.*.amount' => 'required_with:payments|numeric|min:0.01',
            'payments.*.due_at' => 'nullable|date',
            'payments.*.paid_at' => 'nullable|date',
            'payments.*.status' => 'nullable|string|in:PENDING,PAID,CANCELED',
            'payments.*.notes' => 'nullable|string|max:300',
        ];
    }
}