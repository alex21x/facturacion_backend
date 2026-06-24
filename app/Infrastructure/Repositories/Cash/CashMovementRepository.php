<?php

namespace App\Infrastructure\Repositories\Cash;

use App\Application\DTOs\Cash\CashMovementDTO;
use App\Application\DTOs\Cash\CashSessionDetailDTO;
use App\Application\DTOs\Cash\CashSessionRecordDTO;
use App\Application\DTOs\Cash\CashSessionScopeDTO;
use App\Domain\Cash\Repositories\CashMovementRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CashMovementRepository implements CashMovementRepositoryInterface
{
    public function listMovements(int $companyId, ?int $sessionId, ?int $cashRegisterId, int $limit, array $documentRefTypes, array $excludedDocumentStatuses): Collection
    {
        $query = DB::table('sales.cash_movements as cm')
            ->leftJoin('auth.users as u', 'u.id', '=', DB::raw('COALESCE(cm.user_id, cm.created_by)'))
            ->leftJoin('sales.commercial_documents as cd', function ($join) use ($documentRefTypes): void {
                $join->on('cd.id', '=', 'cm.ref_id')
                    ->whereIn('cm.ref_type', $documentRefTypes);
            })
            ->leftJoin('sales.commercial_documents as dsrc', function ($join): void {
                $join->on('dsrc.company_id', '=', 'cd.company_id')
                    ->whereRaw("dsrc.id = COALESCE((cd.metadata->>'source_document_id')::BIGINT, 0)");
            })
            ->leftJoin('master.payment_types as pm', 'pm.id', '=', 'cd.payment_method_id')
            ->leftJoin('auth.users as u_doc', 'u_doc.id', '=', 'cd.created_by')
            ->leftJoin('auth.users as u_src', 'u_src.id', '=', 'dsrc.created_by')
            ->leftJoin('sales.document_kinds as dk_cd', 'dk_cd.id', '=', 'cd.document_kind_id')
            ->leftJoin('sales.document_kinds as dk_cd_code', function ($join): void {
                $join->whereRaw('UPPER(dk_cd_code.code) = UPPER(cd.document_kind)');
            })
            ->leftJoin('sales.document_kinds as dk_src', 'dk_src.id', '=', 'dsrc.document_kind_id')
            ->leftJoin('sales.document_kinds as dk_src_code', function ($join): void {
                $join->whereRaw('UPPER(dk_src_code.code) = UPPER(dsrc.document_kind)');
            })
            ->select([
                'cm.id',
                'cm.cash_register_id',
                'cm.cash_session_id',
                DB::raw("CASE WHEN cm.movement_type = 'INCOME' THEN 'IN' WHEN cm.movement_type = 'EXPENSE' THEN 'OUT' ELSE cm.movement_type END as movement_type"),
                'cm.amount',
                DB::raw('COALESCE(cm.description, cm.notes) as description'),
                'cm.ref_type',
                'cm.ref_id',
                DB::raw("COALESCE(dk_cd.label, dk_cd_code.label, cd.document_kind) as document_kind_label"),
                DB::raw("CASE WHEN cd.id IS NOT NULL THEN CONCAT(cd.series, '-', cd.number) ELSE NULL END as document_number"),
                DB::raw("NULLIF(COALESCE((cd.metadata->>'source_document_id'), ''), '') as source_document_id"),
                DB::raw("CASE WHEN dsrc.id IS NOT NULL THEN CONCAT(dsrc.series, '-', dsrc.number) ELSE NULL END as source_document_number"),
                DB::raw("COALESCE(dk_src.label, dk_src_code.label, dsrc.document_kind) as source_document_kind_label"),
                DB::raw('COALESCE(cm.user_id, cm.created_by) as user_id'),
                DB::raw("CONCAT(u.first_name, ' ', u.last_name) as user_name"),
                DB::raw("CONCAT(u_doc.first_name, ' ', u_doc.last_name) as issuer_user_name"),
                DB::raw("COALESCE(
                    NULLIF(TRIM(COALESCE((cd.metadata->>'origin_seller_user_name'), '')), ''),
                    NULLIF(TRIM(COALESCE(CONCAT(COALESCE(u_src.first_name, ''), ' ', COALESCE(u_src.last_name, '')), '')), '')
                ) as origin_seller_user_name"),
                'cm.movement_at',
                DB::raw("pm.name as payment_method_name"),
            ])
            ->where('cm.company_id', $companyId)
            ->where(function ($query) use ($documentRefTypes, $excludedDocumentStatuses): void {
                $query->whereNotIn('cm.ref_type', $documentRefTypes)
                    ->orWhere(function ($nested) use ($excludedDocumentStatuses): void {
                        $nested->whereNotNull('cd.id')
                            ->whereNotIn('cd.status', $excludedDocumentStatuses);
                    });
            })
            ->orderByDesc('cm.movement_at')
            ->limit($limit);

        if ($sessionId !== null) {
            $query->where('cm.cash_session_id', $sessionId);
        }
        if ($cashRegisterId !== null) {
            $query->where('cm.cash_register_id', $cashRegisterId);
        }

        return $query->get();
    }

    public function findMovementById(int $movementId): ?CashMovementDTO
    {
        $movement = DB::table('sales.cash_movements as cm')
            ->leftJoin('auth.users as u', 'u.id', '=', 'cm.user_id')
            ->select([
                'cm.*',
                DB::raw("CASE WHEN cm.movement_type = 'INCOME' THEN 'IN' WHEN cm.movement_type = 'EXPENSE' THEN 'OUT' ELSE cm.movement_type END as movement_type_ui"),
                DB::raw("CONCAT(u.first_name, ' ', u.last_name) as user_name"),
            ])
            ->where('cm.id', $movementId)
            ->first();

            return $movement ? CashMovementDTO::fromRow($movement) : null;
    }

    public function createMovement(array $payload): int
    {
        return (int) DB::table('sales.cash_movements')->insertGetId($payload);
    }

    public function updateMovementById(int $companyId, int $movementId, array $changes): void
    {
        DB::table('sales.cash_movements')
            ->where('id', $movementId)
            ->where('company_id', $companyId)
            ->update($changes);
    }

    public function findSessionScopeForCommercialDocuments(int $companyId, int $sessionId): ?CashSessionScopeDTO
    {
        $session = DB::table('sales.cash_sessions')
            ->where('id', $sessionId)
            ->where('company_id', $companyId)
            ->first(['id', 'company_id', 'branch_id', 'cash_register_id', 'opened_at', 'closed_at']);

        return $session ? CashSessionScopeDTO::fromRow($session) : null;
    }

    public function listSessionCommercialDocuments(int $companyId, int $sessionId, array $documentRefTypes, array $excludedDocumentStatuses): Collection
    {
        $movementDocRefSubquery = DB::table('sales.cash_movements as cm_doc')
            ->select('cm_doc.ref_id')
            ->where('cm_doc.cash_session_id', $sessionId)
            ->whereIn('cm_doc.ref_type', $documentRefTypes)
            ->distinct();

        return DB::table('sales.commercial_documents as cd')
            ->joinSub($movementDocRefSubquery, 'cm_docs', function ($join): void {
                $join->on('cd.id', '=', 'cm_docs.ref_id');
            })
            ->leftJoin('sales.customers as cust', 'cust.id', '=', 'cd.customer_id')
            ->leftJoin('master.payment_types as pm', 'pm.id', '=', 'cd.payment_method_id')
            ->leftJoin('auth.users as u_doc', 'u_doc.id', '=', 'cd.created_by')
            ->leftJoin('sales.commercial_documents as dsrc', function ($join): void {
                $join->on('dsrc.company_id', '=', 'cd.company_id')
                    ->whereRaw("dsrc.id = COALESCE((cd.metadata->>'source_document_id')::BIGINT, 0)");
            })
            ->leftJoin('auth.users as u_src', 'u_src.id', '=', 'dsrc.created_by')
            ->leftJoin('sales.document_kinds as dk_cd', 'dk_cd.id', '=', 'cd.document_kind_id')
            ->leftJoin('sales.document_kinds as dk_cd_code', function ($join): void {
                $join->whereRaw('UPPER(dk_cd_code.code) = UPPER(cd.document_kind)');
            })
            ->leftJoin('sales.document_kinds as dk_src', 'dk_src.id', '=', 'dsrc.document_kind_id')
            ->leftJoin('sales.document_kinds as dk_src_code', function ($join): void {
                $join->whereRaw('UPPER(dk_src_code.code) = UPPER(dsrc.document_kind)');
            })
            ->select([
                'cd.id',
                'cd.document_kind',
                DB::raw("COALESCE(dk_cd.label, dk_cd_code.label, cd.document_kind) as document_kind_label"),
                'cd.series',
                'cd.number',
                DB::raw("CONCAT(cd.series, '-', cd.number) as document_number"),
                DB::raw("COALESCE(cust.legal_name, cust.trade_name, cust.first_name, '-') as customer_name"),
                DB::raw("NULLIF(COALESCE((cd.metadata->>'customer_vehicle_id'), (cd.metadata->>'customerVehicleId')), '')::BIGINT as customer_vehicle_id"),
                DB::raw("NULLIF(COALESCE(NULLIF(TRIM(CAST(cd.vehicle_plate_snapshot AS TEXT)), ''), (cd.metadata->>'vehicle_plate'), (cd.metadata->>'vehiclePlateSnapshot')), '') as vehicle_plate_snapshot"),
                DB::raw("NULLIF(COALESCE(NULLIF(TRIM(CAST(cd.vehicle_brand_snapshot AS TEXT)), ''), (cd.metadata->>'vehicle_brand'), (cd.metadata->>'vehicleBrand')), '') as vehicle_brand_snapshot"),
                DB::raw("NULLIF(COALESCE(NULLIF(TRIM(CAST(cd.vehicle_model_snapshot AS TEXT)), ''), (cd.metadata->>'vehicle_model'), (cd.metadata->>'vehicleModel')), '') as vehicle_model_snapshot"),
                'pm.name as payment_method_name',
                'cd.total',
                'cd.metadata',
                'cd.status',
                'cd.created_at',
                DB::raw("CONCAT(u_doc.first_name, ' ', u_doc.last_name) as user_name"),
                DB::raw("CONCAT(u_doc.first_name, ' ', u_doc.last_name) as issuer_user_name"),
                DB::raw("COALESCE(
                    NULLIF(TRIM(COALESCE((cd.metadata->>'origin_seller_user_name'), '')), ''),
                    NULLIF(TRIM(COALESCE(CONCAT(COALESCE(u_src.first_name, ''), ' ', COALESCE(u_src.last_name, '')), '')), '')
                ) as origin_seller_user_name"),
                DB::raw("CASE WHEN dsrc.id IS NOT NULL THEN CONCAT(dsrc.series, '-', dsrc.number) ELSE NULL END as source_document_number"),
                DB::raw("COALESCE(dk_src.label, dk_src_code.label, dsrc.document_kind) as source_document_kind_label"),
            ])
            ->where('cd.company_id', $companyId)
            ->whereNotIn('cd.status', $excludedDocumentStatuses)
            ->whereRaw("(
                cd.document_kind NOT IN ('QUOTATION', 'SALES_ORDER')
                OR NOT EXISTS (
                    SELECT 1
                    FROM sales.commercial_documents d2
                    WHERE d2.company_id = cd.company_id
                      AND d2.document_kind IN ('INVOICE', 'RECEIPT')
                      AND d2.status NOT IN ('VOID', 'CANCELED')
                      AND COALESCE((d2.metadata->>'source_document_id')::BIGINT, 0) = cd.id
                )
            )")
            ->orderBy('cd.created_at')
            ->get();
    }

    public function listSyncSessionCommercialDocuments(int $companyId, int $sessionId, array $documentKinds): Collection
    {
        $session = DB::table('sales.cash_sessions')
            ->where('id', $sessionId)
            ->where('company_id', $companyId)
            ->first(['id', 'company_id', 'branch_id', 'cash_register_id', 'opened_at', 'closed_at']);

        if (!$session || !$session->cash_register_id) {
            return collect();
        }

        $query = DB::table('sales.commercial_documents as cd')
            ->where('cd.company_id', $companyId)
            ->where('cd.status', 'ISSUED')
            ->whereIn('cd.document_kind', $documentKinds)
            ->whereRaw("COALESCE((cd.metadata->>'cash_register_id')::BIGINT, 0) = ?", [(int) $session->cash_register_id])
            ->where('cd.created_at', '>=', $session->opened_at)
            ->orderBy('cd.created_at')
            ->select([
                'cd.id',
                'cd.branch_id',
                'cd.payment_method_id',
                'cd.total',
                'cd.paid_total',
                'cd.document_kind',
                'cd.series',
                'cd.number',
                'cd.created_by',
                'cd.created_at',
            ]);

        if ($session->closed_at) {
            $query->where('cd.created_at', '<=', $session->closed_at);
        }

        return $query->get();
    }

    public function listDocumentItems(int $documentId, int $companyId): Collection
    {
        return DB::table('sales.commercial_document_items as cdi')
            ->leftJoin('core.units as u', 'u.id', '=', 'cdi.unit_id')
            ->leftJoin('inventory.products as p', function ($join) use ($companyId): void {
                $join->on('p.id', '=', 'cdi.product_id')
                    ->where('p.company_id', '=', $companyId);
            })
            ->select([
                'cdi.id',
                'cdi.product_id',
                'cdi.description',
                'cdi.qty',
                DB::raw("COALESCE(u.code, '-') as unit_code"),
                'cdi.unit_price',
                'cdi.unit_cost',
                'p.cost_price as product_cost_price',
                'cdi.subtotal as line_subtotal',
                'cdi.total as line_total',
            ])
            ->where('cdi.document_id', $documentId)
            ->orderBy('cdi.line_no')
            ->get();
    }

    public function findSessionDetail(int $companyId, int $sessionId): ?CashSessionDetailDTO
    {
        $session = DB::table('sales.cash_sessions as cs')
            ->leftJoin('auth.users as u', 'u.id', '=', DB::raw('COALESCE(cs.user_id, cs.opened_by)'))
            ->leftJoin('sales.cash_registers as cr', 'cr.id', '=', 'cs.cash_register_id')
            ->select([
                'cs.id',
                'cs.cash_register_id',
                DB::raw('cr.code as cash_register_code'),
                DB::raw('cr.name as cash_register_name'),
                DB::raw('COALESCE(cs.user_id, cs.opened_by) as user_id'),
                DB::raw("CONCAT(u.first_name, ' ', u.last_name) as user_name"),
                'cs.opened_at',
                'cs.closed_at',
                'cs.opening_balance',
                'cs.closing_balance',
                'cs.expected_balance',
                'cs.status',
                'cs.notes',
                'cs.branch_id',
            ])
            ->where('cs.id', $sessionId)
            ->where('cs.company_id', $companyId)
            ->first();

            return $session ? CashSessionDetailDTO::fromRow($session) : null;
    }

    public function upsertSessionCommercialDocumentMovement(int $companyId, int $sessionId, object $document, object $session): bool
    {
        $existingMovement = DB::table('sales.cash_movements')
            ->where('company_id', $companyId)
            ->where('ref_type', 'COMMERCIAL_DOCUMENT')
            ->where('ref_id', (int) $document->id)
            ->orderBy('id')
            ->first(['id', 'cash_register_id', 'cash_session_id']);

        if ($existingMovement) {
            $needsReassign = (int) ($existingMovement->cash_register_id ?? 0) !== (int) $session->cash_register_id
                || (int) ($existingMovement->cash_session_id ?? 0) !== (int) $session->id;

            if ($needsReassign) {
                DB::table('sales.cash_movements')
                    ->where('id', (int) $existingMovement->id)
                    ->update([
                        'cash_register_id' => (int) $session->cash_register_id,
                        'cash_session_id' => (int) $session->id,
                        'branch_id' => $document->branch_id ?? $session->branch_id,
                    ]);

                return true;
            }

            return false;
        }

        return $this->insertCommercialDocumentMovement($companyId, $sessionId, $document, $session);
    }

    public function insertCommercialDocumentMovement(int $companyId, int $sessionId, object $document, object $session): bool
    {
        $amount = (float) ($document->paid_total ?? 0);
        if ($amount <= 0) {
            $amount = (float) ($document->total ?? 0);
        }
        if ($amount <= 0) {
            return false;
        }

        $label = [
            'INVOICE' => 'Factura',
            'RECEIPT' => 'Boleta',
            'CREDIT_NOTE' => 'Nota Credito',
            'DEBIT_NOTE' => 'Nota Debito',
            'SALES_ORDER' => 'Pedido',
        ][$document->document_kind] ?? (string) $document->document_kind;

        $desc = 'Cobro doc ' . $label . ' ' . $document->series . '-' . $document->number;

        DB::table('sales.cash_movements')->insert([
            'company_id' => $companyId,
            'branch_id' => $document->branch_id ?? $session->branch_id,
            'cash_register_id' => (int) $session->cash_register_id,
            'cash_session_id' => (int) $session->id,
            'movement_type' => 'INCOME',
            'payment_method_id' => $document->payment_method_id,
            'amount' => round($amount, 4),
            'description' => $desc,
            'notes' => $desc,
            'ref_type' => 'COMMERCIAL_DOCUMENT',
            'ref_id' => (int) $document->id,
            'created_by' => $document->created_by,
            'user_id' => $document->created_by,
            'movement_at' => $document->created_at ?? now(),
            'created_at' => now(),
        ]);

        return true;
    }

    public function findSessionById(int $sessionId): ?CashSessionRecordDTO
    {
        $session = DB::table('sales.cash_sessions')->where('id', $sessionId)->first();

        return $session ? CashSessionRecordDTO::fromRow($session) : null;
    }

    private function sumSessionMovementsByDirection(int $sessionId, array $movementTypes, array $documentRefTypes, array $excludedDocumentStatuses): float
    {
        return (float) DB::table('sales.cash_movements as cm')
            ->leftJoin('sales.commercial_documents as cd', function ($join) use ($documentRefTypes): void {
                $join->on('cd.id', '=', 'cm.ref_id')
                    ->whereIn('cm.ref_type', $documentRefTypes);
            })
            ->where('cm.cash_session_id', $sessionId)
            ->where(function ($query) use ($documentRefTypes, $excludedDocumentStatuses): void {
                $query->whereNotIn('cm.ref_type', $documentRefTypes)
                    ->orWhere(function ($nested) use ($excludedDocumentStatuses): void {
                        $nested->whereNotNull('cd.id')
                            ->whereNotIn('cd.status', $excludedDocumentStatuses);
                    });
            })
            ->whereIn('cm.movement_type', $movementTypes)
            ->sum('cm.amount');
    }

    public function recalcExpectedBalance(int $sessionId, array $documentRefTypes, array $excludedDocumentStatuses): void
    {
        $sess = DB::table('sales.cash_sessions')->where('id', $sessionId)->first();
        if (!$sess) {
            return;
        }

        $totalIn = $this->sumSessionMovementsByDirection($sessionId, ['IN', 'INCOME'], $documentRefTypes, $excludedDocumentStatuses);
        $totalOut = $this->sumSessionMovementsByDirection($sessionId, ['OUT', 'EXPENSE'], $documentRefTypes, $excludedDocumentStatuses);

        $expectedBalance = round((float) $sess->opening_balance + $totalIn - $totalOut, 4);
        $currentExpectedBalance = round((float) ($sess->expected_balance ?? 0), 4);

        if (abs($expectedBalance - $currentExpectedBalance) < 0.0001) {
            return;
        }

        DB::table('sales.cash_sessions')->where('id', $sessionId)->update([
            'expected_balance' => $expectedBalance,
        ]);
    }
}
