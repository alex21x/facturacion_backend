<?php

namespace App\Http\Requests\Purchases;

use App\Http\Requests\Api\ApiFormRequest;

class ReceivePurchaseOrderRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'company_id' => 'nullable|integer|min:1',
            'issue_at' => 'nullable|date',
            'reference_no' => 'nullable|string|max:60',
            'supplier_reference' => 'nullable|string|max:120',
            'payment_method_id' => 'nullable|integer|min:1',
            'notes' => 'nullable|string|max:300',
            'items' => 'nullable|array|min:1',
            'items.*.product_id' => 'required_with:items|integer|min:1',
            'items.*.qty' => 'required_with:items|numeric|gt:0',
        ];
    }
}