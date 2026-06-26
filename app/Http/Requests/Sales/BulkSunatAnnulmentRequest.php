<?php

namespace App\Http\Requests\Sales;

use App\Http\Requests\Api\ApiFirstErrorFallbackFormRequest;

class BulkSunatAnnulmentRequest extends ApiFirstErrorFallbackFormRequest
{
    public function rules(): array
    {
        return [
            'branch_id' => 'nullable|integer|min:1',
            'warehouse_id' => 'nullable|integer|min:1',
            'cash_register_id' => 'nullable|integer|min:1',
            'source_origin' => 'nullable|string|in:RESTAURANT',
            'document_kind' => 'nullable|string|max:40',
            'document_kind_id' => 'nullable|integer|min:1',
            'conversion_state' => 'nullable|string|in:PENDING,CONVERTED',
            'customer' => 'nullable|string|max:180',
            'customer_id' => 'nullable|integer|min:1',
            'customer_vehicle_id' => 'nullable|integer|min:1',
            'issue_date_from' => 'nullable|date_format:Y-m-d',
            'issue_date_to' => 'nullable|date_format:Y-m-d',
            'series' => 'nullable|string|max:40',
            'number' => 'nullable|string|max:40',
            'max_documents' => 'nullable|integer|min:1|max:500',
            'pause_ms' => 'nullable|integer|min:0|max:10000',
            'reason' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:500',
            'void_password' => 'nullable|string|max:120',
        ];
    }
}
