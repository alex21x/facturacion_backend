<?php

namespace App\Infrastructure\Repositories\Cash;

use App\Application\DTOs\Cash\CashSessionDetailDTO;
use App\Application\DTOs\Cash\CashSessionRecordDTO;
use App\Domain\Cash\Repositories\CashSessionRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CashSessionRepository implements CashSessionRepositoryInterface
{
    public function paginateSessions(int $companyId, ?int $cashRegisterId, ?string $status, int $page, int $limit): array
    {
        $query = DB::table('sales.cash_sessions as cs')
            ->leftJoin('auth.users as u', 'u.id', '=', DB::raw('COALESCE(cs.user_id, cs.opened_by)'))
            ->leftJoin('sales.cash_registers as cr', 'cr.id', '=', 'cs.cash_register_id')
            ->select([
                'cs.id',
                'cs.cash_register_id',
                DB::raw('cr.code  as cash_register_code'),
                DB::raw('cr.name  as cash_register_name'),
                DB::raw('COALESCE(cs.user_id, cs.opened_by) as user_id'),
                DB::raw("CONCAT(u.first_name, ' ', u.last_name) as user_name"),
                'cs.opened_at',
                'cs.closed_at',
                'cs.opening_balance',
                'cs.closing_balance',
                'cs.expected_balance',
                'cs.status',
                'cs.notes',
            ])
            ->where('cs.company_id', $companyId)
            ->orderByDesc('cs.opened_at');

        if ($cashRegisterId !== null) {
            $query->where('cs.cash_register_id', $cashRegisterId);
        }
        if ($status !== null && $status !== '') {
            $query->where('cs.status', $status);
        }

        $total = (clone $query)->count('cs.id');
        $lastPage = (int) max(1, ceil($total / $limit));
        if ($page > $lastPage) {
            $page = $lastPage;
        }

        $rows = $query->offset(($page - 1) * $limit)->limit($limit)->get();

        return [
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $limit,
                'total' => (int) $total,
                'last_page' => $lastPage,
            ],
        ];
    }

    public function findCurrentOpenSession(int $companyId, ?int $cashRegisterId): ?CashSessionDetailDTO
    {
        $query = DB::table('sales.cash_sessions as cs')
            ->leftJoin('sales.cash_registers as cr', 'cr.id', '=', 'cs.cash_register_id')
            ->select([
                'cs.id',
                'cs.cash_register_id',
                DB::raw('cr.code as cash_register_code'),
                DB::raw('cr.name as cash_register_name'),
                'cs.opened_at',
                'cs.opening_balance',
                'cs.expected_balance',
                'cs.status',
                'cs.notes',
            ])
            ->where('cs.company_id', $companyId)
            ->where('cs.status', 'OPEN')
            ->orderByDesc('cs.opened_at');

        if ($cashRegisterId !== null) {
            $query->where('cs.cash_register_id', $cashRegisterId);
        }

        $session = $query->first();

        return $session ? CashSessionDetailDTO::fromRow($session) : null;
    }

    public function findOpenSessionByRegister(int $companyId, int $cashRegisterId): ?CashSessionRecordDTO
    {
        $session = DB::table('sales.cash_sessions')
            ->where('company_id', $companyId)
            ->where('cash_register_id', $cashRegisterId)
            ->where('status', 'OPEN')
            ->first();

        return $session ? CashSessionRecordDTO::fromRow($session) : null;
    }

    public function createSession(array $payload): int
    {
        return (int) DB::table('sales.cash_sessions')->insertGetId($payload);
    }

    public function findSessionById(int $sessionId): ?CashSessionRecordDTO
    {
        $session = DB::table('sales.cash_sessions')->where('id', $sessionId)->first();

        return $session ? CashSessionRecordDTO::fromRow($session) : null;
    }

    public function findSessionByIdAndCompany(int $sessionId, int $companyId): ?CashSessionRecordDTO
    {
        $session = DB::table('sales.cash_sessions')
            ->where('id', $sessionId)
            ->where('company_id', $companyId)
            ->first();

        return $session ? CashSessionRecordDTO::fromRow($session) : null;
    }

    public function sumSessionMovementsByDirection(int $sessionId, array $movementTypes, array $documentRefTypes, array $excludedDocumentStatuses): float
    {
        return (float) DB::table('sales.cash_movements as cm')
            ->leftJoin('sales.commercial_documents as cd', function ($join) use ($documentRefTypes) {
                $join->on('cd.id', '=', 'cm.ref_id')
                    ->whereIn('cm.ref_type', $documentRefTypes);
            })
            ->where('cm.cash_session_id', $sessionId)
            ->where(function ($query) use ($documentRefTypes, $excludedDocumentStatuses) {
                $query->whereNotIn('cm.ref_type', $documentRefTypes)
                    ->orWhere(function ($nested) use ($excludedDocumentStatuses) {
                        $nested->whereNotNull('cd.id')
                            ->whereNotIn('cd.status', $excludedDocumentStatuses);
                    });
            })
            ->whereIn('cm.movement_type', $movementTypes)
            ->sum('cm.amount');
    }

    public function listSessionSalesByPaymentMethod(int $sessionId, int $companyId, array $documentRefTypes, array $excludedDocumentStatuses): Collection
    {
        return DB::table('sales.cash_movements as cm')
            ->join('sales.commercial_documents as cd', 'cd.id', '=', 'cm.ref_id')
            ->leftJoin('master.payment_types as pm', 'pm.id', '=', 'cm.payment_method_id')
            ->select([
                DB::raw('COALESCE(pm.id, 0) as payment_method_id'),
                DB::raw("COALESCE(NULLIF(TRIM(pm.comment), ''), CONCAT('PM', pm.id::text), 'SIN_METODO') as payment_method_code"),
                DB::raw("COALESCE(pm.name, 'Sin método de pago') as payment_method_name"),
                DB::raw('COUNT(DISTINCT cm.ref_id) as document_count'),
                DB::raw('SUM(cm.amount) as total_amount'),
            ])
            ->where('cm.cash_session_id', $sessionId)
            ->where('cm.company_id', $companyId)
            ->whereIn('cm.ref_type', $documentRefTypes)
            ->where('cm.movement_type', 'INCOME')
            ->whereNotIn('cd.status', $excludedDocumentStatuses)
            ->groupBy(
                DB::raw('COALESCE(pm.id, 0)'),
                DB::raw("COALESCE(NULLIF(TRIM(pm.comment), ''), CONCAT('PM', pm.id::text), 'SIN_METODO')"),
                DB::raw("COALESCE(pm.name, 'Sin método de pago')")
            )
            ->orderBy(DB::raw("COALESCE(NULLIF(TRIM(pm.comment), ''), CONCAT('PM', pm.id::text), 'SIN_METODO')"))
            ->get();
    }

    public function closeSession(int $sessionId, array $payload): void
    {
        DB::table('sales.cash_sessions')->where('id', $sessionId)->update($payload);
    }
}
