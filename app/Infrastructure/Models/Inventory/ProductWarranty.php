<?php

namespace App\Infrastructure\Models\Inventory;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProductWarranty extends Model
{
    protected $table = 'inventory.product_warranties';
    protected $connection = 'pgsql';
    public $timestamps = false;

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('status', 1);
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }
}
