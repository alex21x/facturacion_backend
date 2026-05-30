<?php

namespace App\Http\Requests\SunatExceptions;

use App\Http\Requests\Api\ApiFirstErrorFormRequest;

class AuditSunatExceptionsRequest extends ApiFirstErrorFormRequest
{
    public function rules(): array
    {
        return [
            'branch_id' => 'nullable|integer|min:1',
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d',
            'limit' => 'nullable|integer|min:1|max:500',
        ];
    }
}