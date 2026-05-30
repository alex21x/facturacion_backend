<?php

namespace App\Http\Requests\AppConfig;

class UpdateCompanyOperationalLimitMatrixRequest extends AppConfigFormRequest
{
    public function rules(): array
    {
        return [
            'company_id' => 'required|integer|min:1',
            'max_branches_enabled' => 'required|integer|min:1|max:10000',
            'max_warehouses_enabled' => 'required|integer|min:1|max:10000',
            'max_cash_registers_enabled' => 'required|integer|min:1|max:10000',
            'max_cash_registers_per_warehouse' => 'required|integer|min:1|max:10000',
        ];
    }
}