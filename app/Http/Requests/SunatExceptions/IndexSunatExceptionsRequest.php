<?php

namespace App\Http\Requests\SunatExceptions;

use App\Http\Requests\Api\ApiFirstErrorFormRequest;

class IndexSunatExceptionsRequest extends ApiFirstErrorFormRequest
{
    public function rules(): array
    {
        return [
            'branch_id' => 'nullable|integer|min:1',
            'status' => 'nullable|string|max:40',
            'document' => 'nullable|string|max:60',
            'document_kind' => 'nullable|string|max:40',
            'series' => 'nullable|string|max:30',
            'number' => 'nullable|string|max:30',
            'min_age_hours' => 'nullable|integer|min:0|max:720',
            'min_attempts' => 'nullable|integer|min:0|max:100',
            'only_manual_needed' => 'nullable|boolean',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:5|max:100',
        ];
    }
}