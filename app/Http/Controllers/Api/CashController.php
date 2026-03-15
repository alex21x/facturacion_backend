<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CashController extends Controller
{
    // ─────────────────────────────────────────────────────────
    // Sesiones
    // ─────────────────────────────────────────────────────────

    public function sessions(Request $request)
    {
        $authUser     = $request->attributes->get('auth_user');
        $companyId    = (int) $request->query('company_id', $authUser->company_id);
        $cashRegId    = $request->query('cash_register_id');
        $status       = $request->query('status');
        $limit        = min((int) $request->query('limit', 20), 100);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

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
            ->orderByDesc('cs.opened_at')
            ->limit($limit);

        if ($cashRegId !== null && $cashRegId !== '') {
            $query->where('cs.cash_register_id', (int) $cashRegId);
        }
        if ($status !== null && $status !== '') {
            $query->where('cs.status', $status);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function currentSession(Request $request)
    {
        $authUser  = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $cashRegId = $request->query('cash_register_id');

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

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

        if ($cashRegId !== null && $cashRegId !== '') {
            $query->where('cs.cash_register_id', (int) $cashRegId);
        }

        return response()->json(['session' => $query->first()]);
    }

    public function openSession(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');

        $validator = Validator::make($request->all(), [
            'company_id'       => 'nullable|integer|min:1',
            'cash_register_id' => 'required|integer|min:1',
            'opening_balance'  => 'required|numeric|min:0',
            'notes'            => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload   = $validator->validated();
        $companyId = (int) ($payload['company_id'] ?? $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        // Verificar que no haya una sesion abierta para esta caja
        $existing = DB::table('sales.cash_sessions')
            ->where('company_id', $companyId)
            ->where('cash_register_id', (int) $payload['cash_register_id'])
            ->where('status', 'OPEN')
            ->first();

        if ($existing) {
            return response()->json([
                'message'    => 'La caja ya tiene una sesion abierta',
                'session_id' => $existing->id,
            ], 409);
        }

        $openingBalance = round((float) $payload['opening_balance'], 4);

        $sessionId = DB::table('sales.cash_sessions')->insertGetId([
            'company_id'       => $companyId,
            'branch_id'        => $authUser->branch_id ?? null,
            'cash_register_id' => (int) $payload['cash_register_id'],
            'opened_by'        => $authUser->id,
            'user_id'          => $authUser->id,
            'opened_at'        => now(),
            'opening_balance'  => $openingBalance,
            'expected_balance' => $openingBalance,
            'status'           => 'OPEN',
            'notes'            => $payload['notes'] ?? null,
            'created_at'       => now(),
        ]);

        $session = DB::table('sales.cash_sessions')->where('id', $sessionId)->first();

        return response()->json(['message' => 'Sesion de caja abierta', 'session' => $session], 201);
    }

    public function closeSession(Request $request, $id)
    {
        $authUser  = $request->attributes->get('auth_user');
        $companyId = (int) $authUser->company_id;

        $session = DB::table('sales.cash_sessions')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        if (!$session) {
            return response()->json(['message' => 'Sesion no encontrada'], 404);
        }

        if ($session->status !== 'OPEN') {
            return response()->json(['message' => 'La sesion no esta abierta'], 409);
        }

        $validator = Validator::make($request->all(), [
            'closing_balance' => 'required|numeric|min:0',
            'notes'           => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();

        // Recalcular saldo esperado desde los movimientos de la sesion
        $totalIn  = (float) DB::table('sales.cash_movements')
            ->where('cash_session_id', $id)
            ->whereIn('movement_type', ['IN', 'INCOME'])
            ->sum('amount');

        $totalOut = (float) DB::table('sales.cash_movements')
            ->where('cash_session_id', $id)
            ->whereIn('movement_type', ['OUT', 'EXPENSE'])
            ->sum('amount');

        $expectedBalance = round((float) $session->opening_balance + $totalIn - $totalOut, 4);

        DB::table('sales.cash_sessions')->where('id', $id)->update([
            'closed_at'        => now(),
            'closed_by'        => $authUser->id,
            'closing_balance'  => round((float) $payload['closing_balance'], 4),
            'expected_balance' => $expectedBalance,
            'status'           => 'CLOSED',
            'notes'            => $payload['notes'] ?? $session->notes,
        ]);

        $updated = DB::table('sales.cash_sessions')->where('id', $id)->first();

        return response()->json([
            'message'          => 'Sesion de caja cerrada',
            'session'          => $updated,
            'summary'          => [
                'opening_balance'  => (float) $session->opening_balance,
                'total_in'         => $totalIn,
                'total_out'        => $totalOut,
                'expected_balance' => $expectedBalance,
                'closing_balance'  => round((float) $payload['closing_balance'], 4),
                'difference'       => round((float) $payload['closing_balance'] - $expectedBalance, 4),
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────
    // Movimientos
    // ─────────────────────────────────────────────────────────

    public function movements(Request $request)
    {
        $authUser  = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $sessionId = $request->query('session_id');
        $cashRegId = $request->query('cash_register_id');
        $limit     = min((int) $request->query('limit', 50), 200);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $query = DB::table('sales.cash_movements as cm')
            ->leftJoin('auth.users as u', 'u.id', '=', DB::raw('COALESCE(cm.user_id, cm.created_by)'))
            ->select([
                'cm.id',
                'cm.cash_register_id',
                'cm.cash_session_id',
                DB::raw("CASE WHEN cm.movement_type = 'INCOME' THEN 'IN' WHEN cm.movement_type = 'EXPENSE' THEN 'OUT' ELSE cm.movement_type END as movement_type"),
                'cm.amount',
                DB::raw('COALESCE(cm.description, cm.notes) as description'),
                'cm.ref_type',
                'cm.ref_id',
                DB::raw('COALESCE(cm.user_id, cm.created_by) as user_id'),
                DB::raw("CONCAT(u.first_name, ' ', u.last_name) as user_name"),
                'cm.movement_at',
            ])
            ->where('cm.company_id', $companyId)
            ->orderByDesc('cm.movement_at')
            ->limit($limit);

        if ($sessionId !== null && $sessionId !== '') {
            $query->where('cm.cash_session_id', (int) $sessionId);
        }
        if ($cashRegId !== null && $cashRegId !== '') {
            $query->where('cm.cash_register_id', (int) $cashRegId);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function createMovement(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');

        $validator = Validator::make($request->all(), [
            'company_id'       => 'nullable|integer|min:1',
            'cash_register_id' => 'required|integer|min:1',
            'cash_session_id'  => 'nullable|integer|min:1',
            'movement_type'    => 'required|string|in:IN,OUT,INCOME,EXPENSE,ADJUSTMENT',
            'amount'           => 'required|numeric|min:0.01',
            'description'      => 'required|string|max:300',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $payload   = $validator->validated();
        $companyId = (int) ($payload['company_id'] ?? $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        // Buscar sesion abierta si no se especifico
        $sessionId = isset($payload['cash_session_id']) ? (int) $payload['cash_session_id'] : null;

        if ($sessionId === null) {
            $sess = DB::table('sales.cash_sessions')
                ->where('company_id', $companyId)
                ->where('cash_register_id', (int) $payload['cash_register_id'])
                ->where('status', 'OPEN')
                ->orderByDesc('opened_at')
                ->first();

            $sessionId = $sess?->id;
        }

        if ($sessionId === null) {
            return response()->json([
                'message' => 'No hay una sesion abierta para la caja indicada',
            ], 409);
        }

        $movementType = $this->toDbMovementType((string) $payload['movement_type']);

        $movementId = DB::table('sales.cash_movements')->insertGetId([
            'company_id'       => $companyId,
            'branch_id'        => $authUser->branch_id ?? null,
            'cash_register_id' => (int) $payload['cash_register_id'],
            'cash_session_id'  => $sessionId,
            'movement_type'    => $movementType,
            'amount'           => round((float) $payload['amount'], 4),
            'notes'            => $payload['description'],
            'description'      => $payload['description'],
            'ref_type'         => 'MANUAL',
            'ref_id'           => null,
            'created_by'       => $authUser->id,
            'user_id'          => $authUser->id,
            'movement_at'      => now(),
            'created_at'       => now(),
        ]);

        // Actualizar saldo esperado en la sesion
        if ($sessionId) {
            $this->recalcExpectedBalance((int) $sessionId);
        }

        $movement = DB::table('sales.cash_movements')
            ->leftJoin('auth.users as u', 'u.id', '=', 'sales.cash_movements.user_id')
            ->select([
                'sales.cash_movements.*',
                DB::raw("CASE WHEN sales.cash_movements.movement_type = 'INCOME' THEN 'IN' WHEN sales.cash_movements.movement_type = 'EXPENSE' THEN 'OUT' ELSE sales.cash_movements.movement_type END as movement_type_ui"),
                DB::raw("CONCAT(u.first_name, ' ', u.last_name) as user_name"),
            ])
            ->where('sales.cash_movements.id', $movementId)
            ->first();

        if ($movement) {
            $movement->movement_type = $movement->movement_type_ui;
            unset($movement->movement_type_ui);
        }

        return response()->json(['message' => 'Movimiento registrado', 'movement' => $movement], 201);
    }

    // ─────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────

    private function recalcExpectedBalance(int $sessionId): void
    {
        $sess = DB::table('sales.cash_sessions')->where('id', $sessionId)->first();
        if (!$sess) {
            return;
        }

        $totalIn  = (float) DB::table('sales.cash_movements')
            ->where('cash_session_id', $sessionId)
            ->whereIn('movement_type', ['IN', 'INCOME'])
            ->sum('amount');

        $totalOut = (float) DB::table('sales.cash_movements')
            ->where('cash_session_id', $sessionId)
            ->whereIn('movement_type', ['OUT', 'EXPENSE'])
            ->sum('amount');

        DB::table('sales.cash_sessions')->where('id', $sessionId)->update([
            'expected_balance' => round((float) $sess->opening_balance + $totalIn - $totalOut, 4),
        ]);
    }

    private function toDbMovementType(string $movementType): string
    {
        $normalized = strtoupper(trim($movementType));

        if ($normalized === 'IN') {
            return 'INCOME';
        }

        if ($normalized === 'OUT') {
            return 'EXPENSE';
        }

        return $normalized;
    }
}
