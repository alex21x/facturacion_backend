<?php

namespace App\Http\Requests\AppConfig;

class UpdateCompanyVerticalSettingsRequest extends AppConfigFormRequest
{
    public function rules(): array
    {
        return [
            'company_id' => 'nullable|integer|min:1',
            'vertical_code' => 'required|string|max:50',
            'effective_from' => 'nullable|date_format:Y-m-d',
        ];
    }
}