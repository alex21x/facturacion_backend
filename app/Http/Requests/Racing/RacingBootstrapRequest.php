<?php

namespace App\Http\Requests\Racing;

use App\Http\Requests\Api\ApiFirstErrorFormRequest;

class RacingBootstrapRequest extends ApiFirstErrorFormRequest
{
    public function rules(): array
    {
        return [
            'locale' => 'nullable|string|max:10',
        ];
    }
}
