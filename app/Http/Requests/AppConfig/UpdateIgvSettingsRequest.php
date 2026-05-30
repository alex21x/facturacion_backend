<?php

namespace App\Http\Requests\AppConfig;

class UpdateIgvSettingsRequest extends AppConfigFormRequest
{
    public function rules(): array
    {
        return [
            'company_id' => 'nullable|integer|min:1',
            'active_igv_rate_percent' => 'required|numeric|min:0|max:100',
        ];
    }
}