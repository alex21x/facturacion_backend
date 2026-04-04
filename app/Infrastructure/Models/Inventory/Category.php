<?php

namespace App\Infrastructure\Models\Inventory;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $table = 'inventory.categories';
    protected $connection = 'pgsql';
    public $timestamps = false;

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('status', 1);
    }

    public function scopeForCompanyOrGlobal(Builder $query, int $companyId): Builder
    {
        return $query->where(function (Builder $nested) use ($companyId) {
            $nested->where('company_id', $companyId)
                ->orWhereNull('company_id');
        });
    }
}
