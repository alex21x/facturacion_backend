<?php

namespace App\Infrastructure\Models\Inventory;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CompanyUnit extends Model
{
    protected $table = 'appcfg.company_units';
    protected $connection = 'pgsql';
    public $timestamps = false;

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }
}
