<?php

namespace App\Http\Requests\AppConfig;

class UpdateCompanyRateLimitMatrixBulkRequest extends AppConfigFormRequest
{
    public function rules(): array
    {
        return [
            'company_ids' => 'required|array|min:1',
            'company_ids.*' => 'required|integer|min:1',
            'is_enabled' => 'required|boolean',
            'requests_per_minute_read' => 'required|integer|min:100|max:60000',
            'requests_per_minute_write' => 'required|integer|min:100|max:60000',
            'requests_per_minute_reports' => 'required|integer|min:100|max:60000',
            'response_delay_enabled' => 'sometimes|boolean',
            'response_delay_ms' => 'sometimes|integer|min:0|max:' . config('company_response_delay.max_delay_ms'),
            'plan_code' => 'nullable|string|in:BASIC,PRO,ENTERPRISE,CUSTOM',
            'preset_code' => 'nullable|string|in:BASIC,PRO,ENTERPRISE',
        ];
    }
}