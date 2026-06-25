<?php

namespace App\Infrastructure\Repositories\Sales;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReferenceDocumentRepository
{
    private ?bool $taxBridgeAuditTableExists = null;

    public function listReferenceDocuments(
        int $companyId,
        int $customerId,
        ?int $branchId,
        ?string $noteTargetKind,
        string $noteKind,
        int $limit,
        ?int $sellerUserId = null
    ): Collection {
        $hasTaxBridgeAuditTable = $this->hasTaxBridgeAuditTable();

        $query = DB::table('sales.commercial_documents as d')
            ->select([
                'd.id',
                'd.customer_id',
                'd.document_kind',
                'd.series',
                'd.number',
                'd.issue_at',
                'd.total',
                'd.balance_due',
                'd.status',
                DB::raw("COALESCE(notes_agg.applied_credit_total, 0) as applied_credit_total"),
                DB::raw("COALESCE(notes_agg.applied_debit_total,  0) as applied_debit_total"),
                DB::raw("COALESCE(notes_agg.has_credit_note, false) as has_credit_note"),
                DB::raw("COALESCE(notes_agg.has_debit_note,  false) as has_debit_note"),
            ])
            ->where('d.company_id', $companyId)
            ->where('d.customer_id', $customerId)
            ->whereNotIn('d.status', ['VOID', 'CANCELED'])
            ->where(function ($query) use ($hasTaxBridgeAuditTable) {
                $query->whereRaw("UPPER(COALESCE(d.metadata->>'sunat_status', '')) = 'ACCEPTED'")
                    ->orWhereRaw("UPPER(COALESCE(d.metadata->>'sunat_status_label', '')) LIKE '%ACEPTAD%'");

                if ($hasTaxBridgeAuditTable) {
                    $query->orWhereExists(function ($audit) {
                        $audit->select(DB::raw('1'))
                            ->from('sales.tax_bridge_audit_logs as l')
                            ->whereColumn('l.company_id', 'd.company_id')
                            ->whereColumn('l.document_id', 'd.id')
                            ->whereRaw("UPPER(COALESCE(l.sunat_status, '')) = 'ACCEPTED'");
                    });
                }
            })
            ->leftJoinSub(
                DB::table('sales.commercial_documents as nd')
                    ->selectRaw("
                        COALESCE((nd.metadata->>'source_document_id')::BIGINT, 0) AS src_id,
                        SUM(CASE WHEN nd.document_kind = 'CREDIT_NOTE' THEN COALESCE(nd.total, 0) ELSE 0 END) AS applied_credit_total,
                        SUM(CASE WHEN nd.document_kind = 'DEBIT_NOTE'  THEN COALESCE(nd.total, 0) ELSE 0 END) AS applied_debit_total,
                        BOOL_OR(nd.document_kind = 'CREDIT_NOTE') AS has_credit_note,
                        BOOL_OR(nd.document_kind = 'DEBIT_NOTE')  AS has_debit_note
                    ")
                    ->where('nd.company_id', $companyId)
                    ->whereIn('nd.document_kind', ['CREDIT_NOTE', 'DEBIT_NOTE'])
                    ->whereNotIn('nd.status', ['VOID', 'CANCELED'])
                    ->whereRaw("COALESCE((nd.metadata->>'source_document_id')::BIGINT, 0) > 0")
                    ->groupByRaw("COALESCE((nd.metadata->>'source_document_id')::BIGINT, 0)"),
                'notes_agg',
                'notes_agg.src_id',
                '=',
                'd.id'
            );

        if ($noteTargetKind !== null) {
            $query->where('d.document_kind', $noteTargetKind);
        } else {
            $query->whereIn('d.document_kind', ['INVOICE', 'RECEIPT']);
        }

        if ($branchId !== null) {
            $query->where('d.branch_id', $branchId);
        }

        if ($sellerUserId !== null && $sellerUserId > 0) {
            $query->whereRaw("COALESCE(d.seller_user_id, CASE WHEN COALESCE((d.metadata->>'origin_seller_user_id'), '') ~ '^[0-9]+$' THEN (d.metadata->>'origin_seller_user_id')::BIGINT ELSE NULL END, d.created_by) = ?", [$sellerUserId]);
        }

        if ($noteKind === 'CREDIT_NOTE') {
            $query->whereRaw("(COALESCE(d.total, 0) - COALESCE(notes_agg.applied_credit_total, 0)) > 0");
        }

        if ($noteKind === 'DEBIT_NOTE') {
            $query->whereRaw("(COALESCE(d.total, 0) - COALESCE(notes_agg.applied_debit_total, 0)) > 0");
        }

        return $query
            ->orderByRaw('COALESCE(d.created_at, d.issue_at) DESC')
            ->orderBy('d.id', 'desc')
            ->limit($limit)
            ->get();
    }

    public function listPriceTiers(int $companyId): Collection
    {
        return DB::table('sales.price_tiers')
            ->select('id', 'code', 'name', 'min_qty', 'max_qty', 'priority', 'status')
            ->where('company_id', $companyId)
            ->orderBy('priority')
            ->orderBy('min_qty')
            ->get();
    }

    private function hasTaxBridgeAuditTable(): bool
    {
        if ($this->taxBridgeAuditTableExists !== null) {
            return $this->taxBridgeAuditTableExists;
        }

        $this->taxBridgeAuditTableExists = DB::table('information_schema.tables')
            ->where('table_schema', 'sales')
            ->where('table_name', 'tax_bridge_audit_logs')
            ->exists();

        return $this->taxBridgeAuditTableExists;
    }

    public function createPriceTier(int $companyId, array $payload): int
    {
        return (int) DB::table('sales.price_tiers')->insertGetId([
            'company_id' => $companyId,
            'code' => strtoupper(trim((string) $payload['code'])),
            'name' => trim((string) $payload['name']),
            'min_qty' => (float) $payload['min_qty'],
            'max_qty' => array_key_exists('max_qty', $payload) ? ($payload['max_qty'] !== null ? (float) $payload['max_qty'] : null) : null,
            'priority' => (int) ($payload['priority'] ?? 1),
            'status' => (int) ($payload['status'] ?? 1),
        ]);
    }

    public function updatePriceTier(int $companyId, int $id, array $payload): void
    {
        $exists = DB::table('sales.price_tiers')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->exists();

        if (!$exists) {
            throw new \RuntimeException('Price tier not found');
        }

        $current = DB::table('sales.price_tiers')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        $minQty = array_key_exists('min_qty', $payload) ? (float) $payload['min_qty'] : (float) ($current->min_qty ?? 0);
        $maxQty = array_key_exists('max_qty', $payload)
            ? ($payload['max_qty'] !== null ? (float) $payload['max_qty'] : null)
            : ($current->max_qty !== null ? (float) $current->max_qty : null);

        if ($maxQty !== null && $maxQty < $minQty) {
            throw new \RuntimeException('Max qty must be greater than or equal to min qty');
        }

        $updates = [];
        if (array_key_exists('code', $payload) && trim((string) $payload['code']) !== '') {
            $nextCode = strtoupper(trim((string) $payload['code']));
            $existsCode = DB::table('sales.price_tiers')
                ->where('company_id', $companyId)
                ->where('code', $nextCode)
                ->where('id', '!=', $id)
                ->exists();

            if ($existsCode) {
                throw new \RuntimeException('Price tier code already exists');
            }

            $updates['code'] = $nextCode;
        }
        if (array_key_exists('name', $payload) && trim((string) $payload['name']) !== '') {
            $updates['name'] = trim((string) $payload['name']);
        }
        if (array_key_exists('min_qty', $payload)) {
            $updates['min_qty'] = (float) $payload['min_qty'];
        }
        if (array_key_exists('max_qty', $payload)) {
            $updates['max_qty'] = $payload['max_qty'] !== null ? (float) $payload['max_qty'] : null;
        }
        if (array_key_exists('priority', $payload)) {
            $updates['priority'] = (int) $payload['priority'];
        }
        if (array_key_exists('status', $payload)) {
            $updates['status'] = (int) $payload['status'];
        }

        if (!empty($updates)) {
            DB::table('sales.price_tiers')->where('id', $id)->update($updates);
        }
    }
}
