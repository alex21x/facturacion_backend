<?php

namespace App\Http\Requests\AppConfig;

class UpdateCompanyProfileRequest extends AppConfigFormRequest
{
    public function rules(): array
    {
        return [
            'company_id' => 'nullable|integer|min:1',
            'tax_id' => 'nullable|string|max:20',
            'legal_name' => 'nullable|string|max:200',
            'trade_name' => 'nullable|string|max:200',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:60',
            'telefono_movil' => 'nullable|string|max:60',
            'telefono_fijo' => 'nullable|string|max:60',
            'company_description' => 'nullable|string|max:600',
            'email' => 'nullable|email|max:200',
            'website' => 'nullable|url|max:300',
            'ubigeo' => 'nullable|string|max:6',
            'departamento' => 'nullable|string|max:100',
            'provincia' => 'nullable|string|max:100',
            'distrito' => 'nullable|string|max:100',
            'urbanizacion' => 'nullable|string|max:100',
            'sunat_secondary_user' => 'nullable|string|max:100',
            'sunat_secondary_pass' => 'nullable|string|max:100',
            'client_id' => 'nullable|string|max:200',
            'client_secret' => 'nullable|string|max:500',
            'show_payment_brand_icons' => 'nullable|boolean',
            'bank_accounts' => 'nullable|array',
            'bank_accounts.*.bank_name' => 'required_with:bank_accounts.*|string|max:100',
            'bank_accounts.*.account_number' => 'required_with:bank_accounts.*|string|max:50',
            'bank_accounts.*.cci' => 'nullable|string|max:50',
            'bank_accounts.*.account_holder' => 'nullable|string|max:120',
            'bank_accounts.*.currency' => 'nullable|string|max:10',
            'bank_accounts.*.account_type' => 'nullable|string|max:50',
        ];
    }
}