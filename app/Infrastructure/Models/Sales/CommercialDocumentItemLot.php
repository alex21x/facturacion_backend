<?php

namespace App\Infrastructure\Models\Sales;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommercialDocumentItemLot extends Model
{
    protected $table = 'sales.commercial_document_item_lots';
    protected $connection = 'pgsql';
    public $timestamps = false;

    protected $fillable = [
        'document_item_id',
        'lot_id',
        'qty',
    ];

    protected $casts = [
        'qty' => 'float',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(CommercialDocumentItem::class, 'document_item_id');
    }
}
