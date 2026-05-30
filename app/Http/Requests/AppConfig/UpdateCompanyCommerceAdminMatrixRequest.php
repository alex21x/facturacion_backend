<?php

namespace App\Http\Requests\AppConfig;

class UpdateCompanyCommerceAdminMatrixRequest extends AppConfigFormRequest
{
    public function rules(): array
    {
        return [
            'company_id' => 'required|integer|min:1',
            'features' => 'required|array|min:1',
            'features.*' => 'required|boolean',
        ];
    }
}