<?php

namespace App\Http\Requests\Reports;

use App\Http\Requests\Api\ApiFirstErrorFormRequest;

class IndexReportRequest extends ApiFirstErrorFormRequest
{
    public function rules(): array
    {
        return [
            'status' => 'nullable|string|in:PENDING,PROCESSING,COMPLETED,FAILED',
            'report_code' => 'nullable|string|max:80',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:5|max:100',
        ];
    }
}