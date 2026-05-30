<?php

namespace App\Http\Requests\Reports;

use App\Http\Requests\Api\ApiFirstErrorFormRequest;

class StoreReportRequest extends ApiFirstErrorFormRequest
{
    public function rules(): array
    {
        return [
            'report_code' => 'required|string|max:80',
            'branch_id' => 'nullable|integer',
            'filters' => 'nullable|array',
        ];
    }
}