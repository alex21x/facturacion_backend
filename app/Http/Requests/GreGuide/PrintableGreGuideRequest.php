<?php

namespace App\Http\Requests\GreGuide;

use App\Http\Requests\Api\ApiFirstErrorFormRequest;

class PrintableGreGuideRequest extends ApiFirstErrorFormRequest
{
    public function rules(): array
    {
        return [
            'company_id' => 'nullable|integer|min:1',
            'format' => 'nullable|string|in:ticket,a4',
        ];
    }
}
