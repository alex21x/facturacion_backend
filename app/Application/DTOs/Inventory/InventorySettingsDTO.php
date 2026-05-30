<?php

namespace App\Application\DTOs\Inventory;

final class InventorySettingsDTO
{
    public function __construct(
        public readonly ?string $complexity_mode,
        public readonly ?string $inventory_mode,
        public readonly ?string $lot_outflow_strategy,
        public readonly bool $enable_inventory_pro,
        public readonly bool $enable_lot_tracking,
        public readonly bool $enable_expiry_tracking,
        public readonly bool $enable_advanced_reporting,
        public readonly bool $enable_graphical_dashboard,
        public readonly bool $enable_location_control,
        public readonly bool $allow_negative_stock,
        public readonly bool $enforce_lot_for_tracked,
        public readonly int $low_stock_alert_threshold
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            complexity_mode: isset($row->complexity_mode) ? (string) $row->complexity_mode : null,
            inventory_mode: isset($row->inventory_mode) ? (string) $row->inventory_mode : null,
            lot_outflow_strategy: isset($row->lot_outflow_strategy) ? (string) $row->lot_outflow_strategy : null,
            enable_inventory_pro: (bool) ($row->enable_inventory_pro ?? false),
            enable_lot_tracking: (bool) ($row->enable_lot_tracking ?? false),
            enable_expiry_tracking: (bool) ($row->enable_expiry_tracking ?? false),
            enable_advanced_reporting: (bool) ($row->enable_advanced_reporting ?? false),
            enable_graphical_dashboard: (bool) ($row->enable_graphical_dashboard ?? false),
            enable_location_control: (bool) ($row->enable_location_control ?? false),
            allow_negative_stock: (bool) ($row->allow_negative_stock ?? false),
            enforce_lot_for_tracked: (bool) ($row->enforce_lot_for_tracked ?? false),
            low_stock_alert_threshold: (int) ($row->low_stock_alert_threshold ?? 5),
        );
    }
}
