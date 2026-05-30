<?php

namespace App\Http\Requests\Sales;

use App\Http\Requests\Api\ApiFormRequest;

class BulkImportCustomersRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'rows' => 'required|array|min:1|max:20000',
            'rows.*.doc_type' => 'nullable|string|max:20',
            'rows.*.customer_type_id' => 'nullable|integer',
            'rows.*.doc_number' => 'required|string|max:40',
            'rows.*.legal_name' => 'required|string|max:180',
            'rows.*.trade_name' => 'nullable|string|max:180',
            'rows.*.first_name' => 'nullable|string|max:120',
            'rows.*.last_name' => 'nullable|string|max:120',
            'rows.*.plate' => 'nullable|string|max:20',
            'rows.*.address' => 'nullable|string|max:250',
            'rows.*.phone' => 'nullable|string|max:40',
            'rows.*.status' => 'nullable|integer|in:0,1',
        ];
    }
}