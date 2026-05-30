<?php

namespace App\Http\Requests\DailySummary;

use App\Http\Requests\Api\ApiFirstErrorFormRequest;

class StoreDailySummaryRequest extends ApiFirstErrorFormRequest
{
    public function rules(): array
    {
        return [
            'summary_type' => 'required|integer|in:1,3',
            'summary_date' => 'required|date_format:Y-m-d',
            'document_ids' => 'required|array|min:1',
            'document_ids.*' => 'integer|min:1',
            'branch_id' => 'nullable|integer',
            'notes' => 'nullable|string|max:1000',
        ];
    }
}