<?php

namespace App\Http\Requests\AppConfig;

class UpdateOperationalLimitsRequest extends AppConfigFormRequest
{
    public function rules(): array
    {
        return [
            'company_id' => 'nullable|integer|min:1',
            'max_companies_enabled' => 'nullable|integer|min:1|max:10000',
            'max_branches_enabled' => 'nullable|integer|min:1|max:10000',
            'max_warehouses_enabled' => 'nullable|integer|min:1|max:10000',
            'max_cash_registers_enabled' => 'nullable|integer|min:1|max:10000',
            'max_cash_registers_per_warehouse' => 'nullable|integer|min:1|max:10000',
        ];
    }
}