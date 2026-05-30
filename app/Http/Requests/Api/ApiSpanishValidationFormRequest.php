<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator;

abstract class ApiSpanishValidationFormRequest extends ApiFormRequest
{
    protected function validationMessage(Validator $validator): string
    {
        return 'Validacion fallida';
    }
}