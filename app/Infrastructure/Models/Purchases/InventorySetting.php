<?php

namespace App\Infrastructure\Models\Purchases;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InventorySetting extends Model
{
    protected $table = 'inventory.inventory_settings';
    protected $connection = 'pgsql';
    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'complexity_mode',
        'inventory_mode',
        'lot_outflow_strategy',
        'enable_inventory_pro',
        'enable_lot_tracking',
        'enable_expiry_tracking',
        'enable_advanced_reporting',
        'enable_graphical_dashboard',
        'enable_location_control',
        'allow_negative_stock',
        'enforce_lot_for_tracked',
    ];

    protected $casts = [
        'enable_inventory_pro' => 'boolean',
        'enable_lot_tracking' => 'boolean',
        'enable_expiry_tracking' => 'boolean',
        'enable_advanced_reporting' => 'boolean',
        'enable_graphical_dashboard' => 'boolean',
        'enable_location_control' => 'boolean',
        'allow_negative_stock' => 'boolean',
        'enforce_lot_for_tracked' => 'boolean',
    ];

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }
}
