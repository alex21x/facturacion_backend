<?php

namespace App\Infrastructure\Models\Sales;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommercialDocumentPayment extends Model
{
    protected $table = 'sales.commercial_document_payments';
    protected $connection = 'pgsql';
    public $timestamps = true;
    const UPDATED_AT = 'updated_at';
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'document_id',
        'payment_method_id',
        'amount',
        'reference',
        'due_at',
        'paid_at',
        'status',
        'notes',
        'created_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'amount' => 'float',
        'due_at' => 'datetime',
        'paid_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(CommercialDocument::class, 'document_id');
    }
}
