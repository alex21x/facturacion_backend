<?php

namespace App\Http\Requests\AppConfig;

use Illuminate\Validation\Rule;

class UpdateCommerceSettingsRequest extends AppConfigFormRequest
{
    public function rules(): array
    {
        return [
            'company_id' => 'nullable|integer|min:1',
            'branch_id' => 'nullable|integer|min:1',
            'features' => 'required|array|min:1',
            'features.*.feature_code' => ['required', 'string', 'max:100', Rule::in($this->resolveAllowedFeatureCodes())],
            'features.*.is_enabled' => 'required|boolean',
            'features.*.config' => 'nullable',
        ];
    }
}