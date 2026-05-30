<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator;

abstract class ApiFirstErrorFallbackFormRequest extends ApiFormRequest
{
    protected function validationMessage(Validator $validator): string
    {
        return (string) ($validator->errors()->first() ?: 'Validation failed');
    }
}