<?php

namespace App\Http\Requests\AppConfig;

class UpdateCompanySubscriptionAdminMatrixRequest extends AppConfigFormRequest
{
    public function rules(): array
    {
        return [
            'company_id' => 'required|integer|min:1',
            'billing_cycle' => 'nullable|string|in:NONE,ANNUAL,MONTHLY',
            'status' => 'nullable|string|in:ACTIVE,PAUSED,CANCELED',
            'source' => 'nullable|string|in:MANUAL,BRIDGE',
            'alerts_enabled' => 'nullable|boolean',
            'enforcement_mode' => 'nullable|string|in:NONE,SOFT,HARD',
            'starts_at' => 'nullable|date',
            'current_period_starts_at' => 'nullable|date',
            'current_period_ends_at' => 'nullable|date|after_or_equal:current_period_starts_at',
            'reminder_days_before' => 'nullable|integer|min:0|max:30',
            'grace_days' => 'nullable|integer|min:0|max:60',
            'soft_block_days_after' => 'nullable|integer|min:0|max:90',
            'hard_block_days_after' => 'nullable|integer|min:0|max:180',
            'admin_email_enabled' => 'nullable|boolean',
            'admin_email_recipients' => 'nullable|string|max:1000',
            'admin_email_frequency' => 'nullable|string|in:DAILY,STATE_CHANGE',
            'admin_email_send_hour' => 'nullable|integer|min:0|max:23',
            'notes' => 'nullable|string|max:1000',
        ];
    }
}