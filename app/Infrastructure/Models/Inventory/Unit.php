<?php

namespace App\Infrastructure\Models\Inventory;

use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    protected $table = 'core.units';
    protected $connection = 'pgsql';
    public $timestamps = false;
}
