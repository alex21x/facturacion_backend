<?php

namespace App\Http\Requests\AppConfig;

class UpdateGlobalSubscriptionScheduleRequest extends AppConfigFormRequest
{
    public function rules(): array
    {
        return [
            'company_subscription_alert_frequency' => 'required|string|in:DAILY,WEEKLY,MONTHLY',
            'company_subscription_alert_time' => 'required|string|regex:/^(?:[01]?\d|2[0-3]):[0-5]\d$/',
            'company_subscription_weekly_digest_day' => 'required|integer|min:1|max:7',
            'company_subscription_monthly_digest_day' => 'required|integer|min:1|max:28',
        ];
    }
}