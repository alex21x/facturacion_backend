<?php

namespace App\Http\Requests\AppConfig;

class CreateAdminCompanyRequest extends AppConfigFormRequest
{
    public function rules(): array
    {
        return [
            'tax_id' => 'required|string|min:8|max:20',
            'legal_name' => 'required|string|min:3|max:200',
            'trade_name' => 'nullable|string|max:200',
            'email' => 'nullable|email|max:200',
            'phone' => 'nullable|string|max:60',
            'address' => 'nullable|string|max:500',
            'vertical_code' => 'nullable|string|max:50',
            'main_branch_code' => 'nullable|string|max:20',
            'main_branch_name' => 'nullable|string|max:120',
            'create_default_warehouse' => 'nullable|boolean',
            'default_warehouse_code' => 'nullable|string|max:20',
            'default_warehouse_name' => 'nullable|string|max:120',
            'create_default_cash_register' => 'nullable|boolean',
            'default_cash_register_code' => 'nullable|string|max:20',
            'default_cash_register_name' => 'nullable|string|max:120',
            'admin_username' => 'required|string|min:4|max:80',
            'admin_password' => 'required|string|min:8|max:120',
            'admin_first_name' => 'required|string|min:2|max:80',
            'admin_last_name' => 'nullable|string|max:80',
            'admin_email' => 'nullable|email|max:120',
            'admin_phone' => 'nullable|string|max:40',
            'plan_code' => 'nullable|string|in:BASIC,PRO,ENTERPRISE,CUSTOM',
            'preset_code' => 'nullable|string|in:BASIC,PRO,ENTERPRISE',
            'requests_per_minute_read' => 'nullable|integer|min:100|max:60000',
            'requests_per_minute_write' => 'nullable|integer|min:100|max:60000',
            'requests_per_minute_reports' => 'nullable|integer|min:100|max:60000',
        ];
    }
}