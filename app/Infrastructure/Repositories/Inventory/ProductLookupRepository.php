<?php

namespace App\Infrastructure\Repositories\Inventory;

use App\Domain\Inventory\Repositories\ProductLookupRepositoryInterface;
use App\Infrastructure\Models\Inventory\Category;
use App\Infrastructure\Models\Inventory\CompanyUnit;
use App\Infrastructure\Models\Inventory\ProductBrand;
use App\Infrastructure\Models\Inventory\ProductLine;
use App\Infrastructure\Models\Inventory\ProductLocation;
use App\Infrastructure\Models\Inventory\ProductWarranty;
use App\Infrastructure\Models\Inventory\Unit;

class ProductLookupRepository implements ProductLookupRepositoryInterface
{
    public function getProductLookups(int $companyId): array
    {
        $units = Unit::query()
            ->from('core.units as u')
            ->join((new CompanyUnit())->getTable() . ' as cu', function ($join) use ($companyId) {
                $join->on('cu.unit_id', '=', 'u.id')
                    ->where('cu.company_id', '=', $companyId);
            })
            ->select('u.id', 'u.code', 'u.name', 'u.sunat_uom_code')
            ->where('cu.is_enabled', true)
            ->orderBy('name')
            ->get();

        $categories = Category::query()
            ->select('id', 'name')
            ->enabled()
            ->forCompanyOrGlobal($companyId)
            ->orderBy('name')
            ->get();

        $lines = ProductLine::query()
            ->select('id', 'name')
            ->enabled()
            ->forCompany($companyId)
            ->orderBy('name')
            ->get();

        $brands = ProductBrand::query()
            ->select('id', 'name')
            ->enabled()
            ->forCompany($companyId)
            ->orderBy('name')
            ->get();

        $locations = ProductLocation::query()
            ->select('id', 'name')
            ->enabled()
            ->forCompany($companyId)
            ->orderBy('name')
            ->get();

        $warranties = ProductWarranty::query()
            ->select('id', 'name')
            ->enabled()
            ->forCompany($companyId)
            ->orderBy('name')
            ->get();

        return [
            'units' => $units,
            'categories' => $categories,
            'lines' => $lines,
            'brands' => $brands,
            'locations' => $locations,
            'warranties' => $warranties,
        ];
    }
}
