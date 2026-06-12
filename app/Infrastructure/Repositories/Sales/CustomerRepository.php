<?php

namespace App\Infrastructure\Repositories\Sales;

use App\Domain\Sales\Repositories\CustomerRepositoryInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CustomerRepository implements CustomerRepositoryInterface
{
    public function getCustomers(int $companyId, string $search, $status, int $limit, bool $autocomplete, bool $workshopVehicleSearchEnabled): array
    {
        $limit = max(1, min($limit, 10000));
        $search = trim($search);
        $cacheKey = sprintf(
            'sales_customers:%d:%s:%s:%d:%d:%d',
            $companyId,
            md5(mb_strtolower($search)),
            $status === null || $status === '' ? 'all' : (string) (int) $status,
            $limit,
            $autocomplete ? 1 : 0,
            $workshopVehicleSearchEnabled ? 1 : 0
        );

        return Cache::remember($cacheKey, now()->addSeconds(20), function () use ($companyId, $search, $status, $limit, $workshopVehicleSearchEnabled) {
            $query = DB::table('sales.customers as c')
                ->leftJoin('sales.customer_types as ct', 'ct.id', '=', 'c.customer_type_id')
                ->leftJoin('sales.customer_price_profiles as cpp', function ($join) use ($companyId) {
                    $join->on('cpp.customer_id', '=', 'c.id')
                        ->where('cpp.company_id', '=', $companyId);
                })
                ->leftJoin('sales.price_tiers as pt', function ($join) use ($companyId) {
                    $join->on('pt.id', '=', 'cpp.default_tier_id')
                        ->where('pt.company_id', '=', $companyId);
                })
                ->select([
                    'c.id',
                    'c.doc_type',
                    'c.customer_type_id',
                    'ct.name as customer_type_name',
                    'ct.sunat_code as customer_type_sunat_code',
                    'c.doc_number',
                    'c.legal_name',
                    'c.trade_name',
                    'c.first_name',
                    'c.last_name',
                    'c.email',
                    'c.plate',
                    'c.address',
                    'c.phone',
                    'c.status',
                    'cpp.default_tier_id',
                    'cpp.discount_percent',
                    'cpp.status as price_profile_status',
                    'pt.code as default_tier_code',
                    'pt.name as default_tier_name',
                ])
                ->where('c.company_id', $companyId)
                ->orderBy('c.legal_name')
                ->limit($limit);

            if ($search !== '') {
                $like = strlen($search) <= 3 ? $search . '%' : '%' . $search . '%';
                $normalizedDoc = preg_replace('/\D+/', '', $search);

                $query->where(function ($nested) use ($like, $normalizedDoc, $workshopVehicleSearchEnabled) {
                    $nested->where('c.doc_number', 'ilike', $like)
                        ->orWhere('c.legal_name', 'ilike', $like)
                        ->orWhere('c.trade_name', 'ilike', $like)
                        ->orWhere('c.first_name', 'ilike', $like)
                        ->orWhere('c.last_name', 'ilike', $like)
                        ->orWhere('c.plate', 'ilike', $like)
                        ->orWhere('c.phone', 'ilike', $like)
                        ->orWhereRaw("CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, '')) ILIKE ?", [$like]);

                    if ($normalizedDoc !== '') {
                        $nested->orWhereRaw("REGEXP_REPLACE(COALESCE(c.doc_number, ''), '\\D', '', 'g') ILIKE ?", ['%' . $normalizedDoc . '%']);
                    }

                    if ($workshopVehicleSearchEnabled) {
                        $nested->orWhereExists(function ($vehicleQuery) use ($like, $normalizedDoc) {
                            $vehicleQuery->select(DB::raw('1'))
                                ->from('sales.customer_vehicles as cv')
                                ->whereColumn('cv.company_id', 'c.company_id')
                                ->whereColumn('cv.customer_id', 'c.id')
                                ->where('cv.status', 1)
                                ->where(function ($vehicleNested) use ($like, $normalizedDoc) {
                                    $vehicleNested->where('cv.plate', 'ilike', $like)
                                        ->orWhere('cv.brand', 'ilike', $like)
                                        ->orWhere('cv.model', 'ilike', $like);

                                    if ($normalizedDoc !== '') {
                                        $normalizedLike = strlen($normalizedDoc) <= 3 ? $normalizedDoc . '%' : '%' . $normalizedDoc . '%';
                                        $vehicleNested->orWhere('cv.plate_normalized', 'ilike', $normalizedLike);
                                    }
                                });
                        });
                    }
                });
            }

            if ($status !== null && $status !== '') {
                $query->where('c.status', (int) $status);
            }

            return $query->get()->map(function ($row) {
                return $this->customerSuggestionFromRow($row);
            })->values()->all();
        });
    }

    private function customerSuggestionFromRow($row): array
    {
        $name = $row->legal_name;

        if (!$name) {
            $name = trim(collect([$row->first_name, $row->last_name])->filter()->implode(' '));
        }

        return [
            'id' => (int) $row->id,
            'doc_type' => $row->doc_type,
            'customer_type_id' => $row->customer_type_id !== null ? (int) $row->customer_type_id : null,
            'customer_type_name' => $row->customer_type_name,
            'customer_type_sunat_code' => $row->customer_type_sunat_code !== null ? (int) $row->customer_type_sunat_code : null,
            'doc_number' => $row->doc_number,
            'name' => $name ?: ('Cliente #' . $row->id),
            'trade_name' => $row->trade_name,
            'email' => $row->email,
            'plate' => $row->plate,
            'address' => $row->address,
            'phone' => $row->phone,
            'default_tier_id' => $row->default_tier_id !== null ? (int) $row->default_tier_id : null,
            'default_tier_code' => $row->default_tier_code,
            'default_tier_name' => $row->default_tier_name,
            'discount_percent' => $row->discount_percent !== null ? (float) $row->discount_percent : 0,
            'price_profile_status' => $row->price_profile_status !== null ? (int) $row->price_profile_status : 1,
        ];
    }

    public function findCustomerByDocument(int $companyId, string $document): ?\App\Application\DTOs\Sales\SalesCustomerProfileDTO
    {
        $row = DB::table('sales.customers as c')
            ->leftJoin('sales.customer_types as ct', 'ct.id', '=', 'c.customer_type_id')
            ->leftJoin('sales.customer_price_profiles as cpp', function ($join) use ($companyId) {
                $join->on('cpp.customer_id', '=', 'c.id')
                    ->where('cpp.company_id', '=', $companyId);
            })
            ->leftJoin('sales.price_tiers as pt', function ($join) use ($companyId) {
                $join->on('pt.id', '=', 'cpp.default_tier_id')
                    ->where('pt.company_id', '=', $companyId);
            })
            ->select([
                'c.id',
                'c.doc_type',
                'c.customer_type_id',
                'ct.name as customer_type_name',
                'ct.sunat_code as customer_type_sunat_code',
                'c.doc_number',
                'c.legal_name',
                'c.trade_name',
                'c.first_name',
                'c.last_name',
                'c.email',
                'c.plate',
                'c.address',
                'c.phone',
                'cpp.default_tier_id',
                'cpp.discount_percent',
                'cpp.status as price_profile_status',
                'pt.code as default_tier_code',
                'pt.name as default_tier_name',
            ])
            ->where('c.company_id', $companyId)
            ->where('c.doc_number', $document)
            ->orderByDesc('c.id')
            ->first();

        return $row ? \App\Application\DTOs\Sales\SalesCustomerProfileDTO::fromRow($row) : null;
    }

    public function findCustomerById(int $companyId, int $id): ?\App\Application\DTOs\Sales\SalesCustomerProfileDTO
    {
        $row = DB::table('sales.customers as c')
            ->leftJoin('sales.customer_types as ct', 'ct.id', '=', 'c.customer_type_id')
            ->leftJoin('sales.customer_price_profiles as cpp', function ($join) use ($companyId) {
                $join->on('cpp.customer_id', '=', 'c.id')
                    ->where('cpp.company_id', '=', $companyId);
            })
            ->leftJoin('sales.price_tiers as pt', function ($join) use ($companyId) {
                $join->on('pt.id', '=', 'cpp.default_tier_id')
                    ->where('pt.company_id', '=', $companyId);
            })
            ->select([
                'c.id',
                'c.doc_type',
                'c.customer_type_id',
                'ct.name as customer_type_name',
                'ct.sunat_code as customer_type_sunat_code',
                'c.doc_number',
                'c.legal_name',
                'c.trade_name',
                'c.first_name',
                'c.last_name',
                'c.email',
                'c.plate',
                'c.address',
                'c.phone',
                'cpp.default_tier_id',
                'cpp.discount_percent',
                'cpp.status as price_profile_status',
                'pt.code as default_tier_code',
                'pt.name as default_tier_name',
            ])
            ->where('c.company_id', $companyId)
            ->where('c.id', $id)
            ->first();

        return $row ? \App\Application\DTOs\Sales\SalesCustomerProfileDTO::fromRow($row) : null;
    }

    public function findCustomerIdentityByDocument(int $companyId, string $document): ?\App\Application\DTOs\Sales\SalesCustomerIdentityRecordDTO
    {
        $row = DB::table('sales.customers')
            ->select('id', 'status')
            ->where('company_id', $companyId)
            ->where('doc_number', $document)
            ->orderByDesc('id')
            ->first();

        return $row ? \App\Application\DTOs\Sales\SalesCustomerIdentityRecordDTO::fromRow($row) : null;
    }

    public function insertCustomer(array $data): int
    {
        return (int) DB::table('sales.customers')->insertGetId($data);
    }

    public function updateCustomerById(int $companyId, int $id, array $data): void
    {
        DB::table('sales.customers')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->update($data);
    }

    public function customerExists(int $companyId, int $id): bool
    {
        return DB::table('sales.customers')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->exists();
    }

    public function resolveCustomerTypeIdBySunatCode(int $sunatCode): ?int
    {
        $row = DB::table('sales.customer_types')
            ->where('sunat_code', $sunatCode)
            ->where('is_active', true)
            ->orderBy('id')
            ->select('id')
            ->first();

        return $row ? (int) $row->id : null;
    }

    public function getActiveCustomerTypeIdsMap(): array
    {
        return DB::table('sales.customer_types')
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->flip()
            ->all();
    }

    public function getCustomerTypeSunatCodeById(int $typeId): ?int
    {
        $value = DB::table('sales.customer_types')
            ->where('id', $typeId)
            ->value('sunat_code');

        return $value !== null ? (int) $value : null;
    }

    public function getExistingCustomersByDocument(int $companyId): array
    {
        return DB::table('sales.customers')
            ->select('id', 'doc_number', 'status')
            ->where('company_id', $companyId)
            ->whereNotNull('doc_number')
            ->orderByDesc('id')
            ->get()
            ->reduce(function (array $acc, $row) {
                $docKey = strtoupper(trim((string) ($row->doc_number ?? '')));
                if ($docKey !== '' && !isset($acc[$docKey])) {
                    $acc[$docKey] = [
                        'id' => (int) $row->id,
                        'status' => (int) ($row->status ?? 0),
                    ];
                }

                return $acc;
            }, []);
    }

    public function findCustomerPriceProfile(int $companyId, int $customerId): ?\App\Application\DTOs\Sales\SalesCustomerPriceProfileDTO
    {
        $row = DB::table('sales.customer_price_profiles')
            ->where('company_id', $companyId)
            ->where('customer_id', $customerId)
            ->first();

        return $row ? \App\Application\DTOs\Sales\SalesCustomerPriceProfileDTO::fromRow($row) : null;
    }

    public function upsertCustomerPriceProfile(int $companyId, int $customerId, ?int $defaultTierId, float $discountPercent, int $status): void
    {
        DB::table('sales.customer_price_profiles')->updateOrInsert(
            [
                'company_id' => $companyId,
                'customer_id' => $customerId,
            ],
            [
                'default_tier_id' => $defaultTierId,
                'discount_percent' => $discountPercent,
                'status' => $status,
            ]
        );
    }

    public function tierExists(int $companyId, int $tierId): bool
    {
        return DB::table('sales.price_tiers')
            ->where('company_id', $companyId)
            ->where('id', $tierId)
            ->exists();
    }
}
