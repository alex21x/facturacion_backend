<?php

namespace App\Http\Requests\AppConfig;

class UpdateCompanyRateLimitMatrixRequest extends AppConfigFormRequest
{
    public function rules(): array
    {
        return [
            'company_id' => 'required|integer|min:1',
            'is_enabled' => 'required|boolean',
            'requests_per_minute_read' => 'required|integer|min:100|max:60000',
            'requests_per_minute_write' => 'required|integer|min:100|max:60000',
            'requests_per_minute_reports' => 'required|integer|min:100|max:60000',
            'plan_code' => 'nullable|string|in:BASIC,PRO,ENTERPRISE,CUSTOM',
            'preset_code' => 'nullable|string|in:BASIC,PRO,ENTERPRISE',
        ];
    }
}