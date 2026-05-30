<?php

namespace App\Http\Requests\MasterData;

class InventorySettingsRequest extends MasterDataFormRequest
{
    public function rules(): array
    {
        return [
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
        ];
    }
}