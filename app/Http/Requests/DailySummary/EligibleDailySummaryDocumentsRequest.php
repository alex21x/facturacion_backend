<?php

namespace App\Http\Requests\DailySummary;

use App\Http\Requests\Api\ApiFirstErrorFormRequest;

class EligibleDailySummaryDocumentsRequest extends ApiFirstErrorFormRequest
{
    public function rules(): array
    {
        return [
            'summary_type' => 'required|integer|in:1,3',
            'date' => 'required|date_format:Y-m-d',
            'branch_id' => 'nullable|integer',
        ];
    }
}