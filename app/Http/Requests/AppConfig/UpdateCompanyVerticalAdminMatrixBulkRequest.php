<?php

namespace App\Http\Requests\AppConfig;

class UpdateCompanyVerticalAdminMatrixBulkRequest extends AppConfigFormRequest
{
    public function rules(): array
    {
        return [
            'company_ids' => 'required|array|min:1',
            'company_ids.*' => 'required|integer|min:1',
            'vertical_code' => 'required|string|max:50',
            'is_enabled' => 'required|boolean',
            'make_primary' => 'nullable|boolean',
            'effective_from' => 'nullable|date_format:Y-m-d',
        ];
    }
}