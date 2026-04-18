<?php

namespace App\Infrastructure\Models\Purchases;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    protected $table = 'master.payment_types';
    protected $connection = 'pgsql';
    public $timestamps = false;

    protected $fillable = [
        'name',
        'comment',
        'is_active',
        'status',
    ];

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where(function (Builder $nested) {
            $nested->where('is_active', 1)
                ->orWhereIn('status', [1, 2]);
        });
    }
}
