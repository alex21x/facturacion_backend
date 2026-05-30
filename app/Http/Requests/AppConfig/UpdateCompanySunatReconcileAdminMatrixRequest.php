<?php

namespace App\Http\Requests\AppConfig;

class UpdateCompanySunatReconcileAdminMatrixRequest extends AppConfigFormRequest
{
    public function rules(): array
    {
        return [
            'company_id' => 'required|integer|min:1',
            'tax_bridge_enabled' => 'nullable|boolean',
            'auto_reconcile_enabled' => 'nullable|boolean',
            'reconcile_batch_size' => 'nullable|integer|min:5|max:200',
            'reconcile_retry_base_minutes' => 'nullable|integer|min:1|max:180',
            'reconcile_retry_max_minutes' => 'nullable|integer|min:5|max:1440',
            'reconcile_warn_attempts' => 'nullable|integer|min:1|max:50',
            'sunat_exception_notify_enabled' => 'nullable|boolean',
            'sunat_exception_notify_hours' => 'nullable|integer|min:1|max:168',
            'sunat_alert_repeat_minutes' => 'nullable|integer|min:10|max:1440',
            'sunat_exception_notify_limit' => 'nullable|integer|min:1|max:500',
        ];
    }
}