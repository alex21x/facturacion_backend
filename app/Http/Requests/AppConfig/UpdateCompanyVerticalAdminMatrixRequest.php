<?php

namespace App\Http\Requests\AppConfig;

class UpdateCompanyVerticalAdminMatrixRequest extends AppConfigFormRequest
{
    public function rules(): array
    {
        return [
            'company_id' => 'required|integer|min:1',
            'vertical_code' => 'required|string|max:50',
            'is_enabled' => 'required|boolean',
            'make_primary' => 'nullable|boolean',
            'effective_from' => 'nullable|date_format:Y-m-d',
        ];
    }
}