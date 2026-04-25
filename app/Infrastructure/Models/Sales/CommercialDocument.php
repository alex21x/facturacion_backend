<?php

namespace App\Infrastructure\Models\Sales;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommercialDocument extends Model
{
    protected $table = 'sales.commercial_documents';
    protected $connection = 'pgsql';
    public $timestamps = true;
    const UPDATED_AT = 'updated_at';
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'company_id',
        'branch_id',
        'warehouse_id',
        'document_kind',
        'series',
        'number',
        'issue_at',
        'due_at',
        'customer_id',
        'customer_vehicle_id',
        'currency_id',
        'payment_method_id',
        'exchange_rate',
        'status',
        'subtotal',
        'tax_total',
        'discount_total',
        'total',
        'paid_total',
        'balance_due',
        'notes',
        'metadata',
        'vehicle_plate_snapshot',
        'vehicle_brand_snapshot',
        'vehicle_model_snapshot',
        'seller_user_id',
        'created_by',
        'updated_by',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'metadata' => 'json',
        'subtotal' => 'float',
        'tax_total' => 'float',
        'discount_total' => 'float',
        'total' => 'float',
        'paid_total' => 'float',
        'balance_due' => 'float',
        'exchange_rate' => 'float',
        'issue_at' => 'datetime',
        'due_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(CommercialDocumentItem::class, 'document_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CommercialDocumentPayment::class, 'document_id');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeExcludeCanceledStatuses(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['VOID', 'CANCELED']);
    }

    public function scopeForSourceDocument(Builder $query, int $sourceDocumentId): Builder
    {
        return $query->whereRaw("COALESCE((metadata->>'source_document_id')::BIGINT, 0) = ?", [$sourceDocumentId]);
    }
}
