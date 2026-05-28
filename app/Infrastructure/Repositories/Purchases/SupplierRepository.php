<?php

namespace App\Infrastructure\Repositories\Purchases;

use App\Domain\Purchases\Repositories\SupplierRepositoryInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SupplierRepository implements SupplierRepositoryInterface
{
    public function getSuppliers(int $companyId, string $search, int $limit, bool $autocomplete): array
    {
        $limit = max(1, min($limit, 10000));
        $search = trim($search);
        $cacheKey = sprintf(
            'purchases_suppliers:%d:%s:%d:%d',
            $companyId,
            md5(mb_strtolower($search)),
            $limit,
            $autocomplete ? 1 : 0
        );

        return Cache::remember($cacheKey, now()->addSeconds(20), function () use ($companyId, $search, $limit, $autocomplete) {
            $query = DB::table('inventory.purchase_suppliers')
                ->select(['id', 'doc_type', 'doc_number', 'legal_name', 'address', 'phone', 'source'])
                ->where('company_id', $companyId)
                ->orderBy($autocomplete ? DB::raw('COALESCE(last_used_at, updated_at, created_at) DESC') : 'legal_name')
                ->limit($limit);

            if ($search !== '') {
                $like = '%' . $search . '%';
                $normalizedDoc = preg_replace('/\D+/', '', $search);

                $query->where(function ($nested) use ($like, $normalizedDoc) {
                    $nested->where('doc_number', 'ilike', $like)
                        ->orWhere('legal_name', 'ilike', $like)
                        ->orWhere('address', 'ilike', $like)
                        ->orWhere('phone', 'ilike', $like);

                    if ($normalizedDoc !== '') {
                        $nested->orWhereRaw("REGEXP_REPLACE(COALESCE(doc_number, ''), '\\D', '', 'g') ILIKE ?", ['%' . $normalizedDoc . '%']);
                    }
                });
            }

            $rows = $query->get();

            return $rows->map(function ($row) {
                return $this->supplierSuggestionFromRow($row);
            })->values()->all();
        });
    }

    private function supplierSuggestionFromRow($row): array
    {
        return [
            'id' => (int) $row->id,
            'doc_type' => $row->doc_type,
            'doc_number' => $row->doc_number,
            'legal_name' => $row->legal_name,
            'address' => $row->address,
            'phone' => $row->phone,
            'source' => $row->source,
        ];
    }

    public function findSupplierByDocument(int $companyId, string $document): ?object
    {
        $row = DB::table('inventory.purchase_suppliers')
            ->select(['id', 'doc_type', 'doc_number', 'legal_name', 'address', 'phone', 'source'])
            ->where('company_id', $companyId)
            ->where('doc_number', $document)
            ->first();

        return $row ? (object) $row : null;
    }

    public function getExistingSupplierDocumentSet(int $companyId): array
    {
        return DB::table('inventory.purchase_suppliers')
            ->where('company_id', $companyId)
            ->pluck('doc_number')
            ->map(fn ($value) => (string) $value)
            ->flip()
            ->all();
    }

    public function insertSupplierBatch(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        DB::table('inventory.purchase_suppliers')->insert($rows);
    }

    public function upsertSupplierByDocument(int $companyId, array $data): void
    {
        DB::statement(
            'INSERT INTO inventory.purchase_suppliers (company_id, doc_type, doc_number, legal_name, address, phone, source, created_at, updated_at, last_used_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW()) '
            . 'ON CONFLICT (company_id, doc_number) DO UPDATE SET '
            . 'doc_type = EXCLUDED.doc_type, legal_name = EXCLUDED.legal_name, address = EXCLUDED.address, phone = EXCLUDED.phone, source = EXCLUDED.source, updated_at = NOW(), last_used_at = NOW()',
            [
                $companyId,
                (string) ($data['doc_type'] ?? ''),
                (string) ($data['doc_number'] ?? ''),
                (string) ($data['legal_name'] ?? ''),
                $data['address'] ?? null,
                $data['phone'] ?? null,
                $data['source'] ?? null,
            ]
        );
    }
}