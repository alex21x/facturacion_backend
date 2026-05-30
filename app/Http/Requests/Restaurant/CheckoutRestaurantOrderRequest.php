<?php

namespace App\Http\Requests\Restaurant;

use App\Http\Requests\Api\ApiFormRequest;

class CheckoutRestaurantOrderRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'target_document_kind' => 'required|string|in:INVOICE,RECEIPT,SALES_ORDER',
            'series' => 'nullable|string|max:10',
            'cash_register_id' => 'nullable|integer|min:1',
            'payment_method_id' => 'nullable|integer|min:1',
            'notes' => 'nullable|string|max:500',
        ];
    }
}