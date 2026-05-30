<?php

namespace App\Http\Requests\Purchases;

use App\Http\Requests\Api\ApiFormRequest;

class BulkImportSuppliersRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'rows' => 'required|array|min:1|max:5000',
            'rows.*.doc_type' => 'nullable|string|max:10',
            'rows.*.doc_number' => 'required|string|max:40',
            'rows.*.legal_name' => 'required|string|max:255',
            'rows.*.address' => 'nullable|string|max:255',
            'rows.*.phone' => 'nullable|string|max:40',
            'rows.*.source' => 'nullable|string|max:20',
        ];
    }
}