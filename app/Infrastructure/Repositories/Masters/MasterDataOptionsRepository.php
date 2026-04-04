<?php

namespace App\Infrastructure\Repositories\Masters;

use App\Domain\Masters\Repositories\MasterDataOptionsRepositoryInterface;
use Illuminate\Support\Facades\DB;

class MasterDataOptionsRepository implements MasterDataOptionsRepositoryInterface
{
    public function getOptions(int $companyId): array
    {
        $branches = DB::table('core.branches')
            ->select('id', 'code', 'name')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->orderByDesc('is_main')
            ->orderBy('name')
            ->get();

        $warehouses = DB::table('inventory.warehouses')
            ->select('id', 'branch_id', 'code', 'name')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->orderBy('name')
            ->get();

        $products = DB::table('inventory.products')
            ->select('id', 'sku', 'name')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('status', 1)
            ->orderBy('name')
            ->limit(200)
            ->get();

        return [
            'branches' => $branches,
            'warehouses' => $warehouses,
            'products' => $products,
        ];
    }
}
