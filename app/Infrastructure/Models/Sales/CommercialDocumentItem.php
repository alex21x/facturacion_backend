<?php

namespace App\Infrastructure\Models\Sales;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommercialDocumentItem extends Model
{
    protected $table = 'sales.commercial_document_items';
    protected $connection = 'pgsql';
    public $timestamps = false;

    protected $fillable = [
        'document_id',
        'line_no',
        'product_id',
        'unit_id',
        'price_tier_id',
        'tax_category_id',
        'description',
        'qty',
        'qty_base',
        'conversion_factor',
        'base_unit_price',
        'unit_price',
        'unit_cost',
        'wholesale_discount_percent',
        'price_source',
        'discount_total',
        'tax_total',
        'subtotal',
        'total',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'json',
        'qty' => 'float',
        'qty_base' => 'float',
        'conversion_factor' => 'float',
        'base_unit_price' => 'float',
        'unit_price' => 'float',
        'unit_cost' => 'float',
        'wholesale_discount_percent' => 'float',
        'discount_total' => 'float',
        'tax_total' => 'float',
        'subtotal' => 'float',
        'total' => 'float',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(CommercialDocument::class, 'document_id');
    }

    public function lots(): HasMany
    {
        return $this->hasMany(CommercialDocumentItemLot::class, 'document_item_id');
    }
}
