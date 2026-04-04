<?php

namespace App\Infrastructure\Models\Purchases;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    protected $table = 'core.payment_methods';
    protected $connection = 'pgsql';
    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
        'status',
    ];

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('status', 1);
    }
}
