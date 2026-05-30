<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Api\ApiFormRequest;

class RefreshRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'refresh_token' => 'required|string|min:32|max:255',
            'device_id' => 'required|string|max:120',
        ];
    }
}