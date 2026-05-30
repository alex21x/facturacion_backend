<?php

namespace App\Http\Requests\GreGuide;

use App\Http\Requests\Api\ApiFirstErrorFormRequest;

class SearchUbigeosRequest extends ApiFirstErrorFormRequest
{
    public function rules(): array
    {
        return [
            'q' => 'required|string|min:2|max:80',
            'limit' => 'nullable|integer|min:1|max:60',
        ];
    }
}
