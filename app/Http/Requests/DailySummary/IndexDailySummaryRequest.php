<?php

namespace App\Http\Requests\DailySummary;

use App\Http\Requests\Api\ApiFirstErrorFormRequest;

class IndexDailySummaryRequest extends ApiFirstErrorFormRequest
{
    public function rules(): array
    {
        return [
            'summary_type' => 'required|integer|in:1,3',
            'date' => 'nullable|date_format:Y-m-d',
            'status' => 'nullable|string|in:DRAFT,SENDING,SENT,ACCEPTED,REJECTED,ERROR',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:5|max:100',
        ];
    }
}