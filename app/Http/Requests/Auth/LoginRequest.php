<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Api\ApiFormRequest;

class LoginRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'username' => 'required|string|max:80',
            'password' => 'required|string|max:255',
            'device_id' => 'required|string|max:120',
            'device_name' => 'nullable|string|max:120',
            'company_access_slug' => 'nullable|string|max:120',
        ];
    }
}