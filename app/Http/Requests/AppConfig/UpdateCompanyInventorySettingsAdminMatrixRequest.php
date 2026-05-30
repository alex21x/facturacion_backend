<?php

namespace App\Http\Requests\AppConfig;

class UpdateCompanyInventorySettingsAdminMatrixRequest extends AppConfigFormRequest
{
    public function rules(): array
    {
        return [
            'company_id' => 'required|integer|min:1',
            'complexity_mode' => 'nullable|string|in:BASIC,ADVANCED',
            'inventory_mode' => 'nullable|string|in:KARDEX_SIMPLE,LOT_TRACKING',
            'lot_outflow_strategy' => 'nullable|string|in:MANUAL,FIFO,FEFO',
            'enable_inventory_pro' => 'nullable|boolean',
            'enable_lot_tracking' => 'nullable|boolean',
            'enable_expiry_tracking' => 'nullable|boolean',
            'enable_advanced_reporting' => 'nullable|boolean',
            'enable_graphical_dashboard' => 'nullable|boolean',
            'enable_location_control' => 'nullable|boolean',
            'allow_negative_stock' => 'nullable|boolean',
            'enforce_lot_for_tracked' => 'nullable|boolean',
            'low_stock_alert_threshold' => $this->hasInventoryLowStockAlertThreshold()
                ? 'nullable|integer|min:0|max:9999'
                : 'nullable',
        ];
    }
}