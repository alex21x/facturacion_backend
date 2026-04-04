<?php

namespace App\Infrastructure\Models\Sales;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SeriesNumber extends Model
{
    protected $table = 'sales.series_numbers';
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
        'current_number',
        'is_enabled',
        'updated_by',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'current_number' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForDocumentSeries(Builder $query, string $documentKind, string $series): Builder
    {
        return $query
            ->where('document_kind', $documentKind)
            ->where('series', $series);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    public function scopeForBranchAndWarehouse(Builder $query, ?int $branchId, ?int $warehouseId): Builder
    {
        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        } else {
            $query->whereNull('branch_id');
        }

        if ($warehouseId !== null) {
            $query->where('warehouse_id', $warehouseId);
        } else {
            $query->whereNull('warehouse_id');
        }

        return $query;
    }
}
