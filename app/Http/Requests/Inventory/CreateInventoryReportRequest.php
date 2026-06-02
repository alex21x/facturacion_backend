<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\Api\ApiFormRequest;

class CreateInventoryReportRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'company_id' => 'nullable|integer|min:1',
            'branch_id' => 'nullable|integer|min:1',
            'report_type' => 'required|string|in:STOCK_SNAPSHOT,LOW_STOCK,KARDEX_PHYSICAL,KARDEX_VALUED,LOT_EXPIRY,INVENTORY_CUT',
            'filters' => 'nullable|array',
            'run_async' => 'nullable|boolean',
        ];
    }
}