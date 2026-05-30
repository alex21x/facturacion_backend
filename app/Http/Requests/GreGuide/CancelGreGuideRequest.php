<?php

namespace App\Http\Requests\GreGuide;

use App\Http\Requests\Api\ApiFirstErrorFormRequest;

class CancelGreGuideRequest extends ApiFirstErrorFormRequest
{
    public function rules(): array
    {
        return [
            'reason' => 'required|string|max:1000',
        ];
    }
}
