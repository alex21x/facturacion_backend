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
        $page         = (int) $request->query('page', 1);
        $limit        = (int) $request->query('per_page', $request->query('limit', 10));

        if ($page < 1) {
            $page = 1;
        }
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 100) {
            $limit = 100;
        }

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Ambito de empresa invalido'], 403);
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
            ->orderByDesc('cs.opened_at');

        if ($cashRegId !== null && $cashRegId !== '') {
            $query->where('cs.cash_register_id', (int) $cashRegId);
        }
        if ($status !== null && $status !== '') {
            $query->where('cs.status', $status);
        }

        $total = (clone $query)->count('cs.id');
        $lastPage = (int) max(1, ceil($total / $limit));
        if ($page > $lastPage) {
            $page = $lastPage;
        }

        $rows = $query
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $limit,
                'total' => (int) $total,
                'last_page' => $lastPage,
            ],
        ]);
    }

    public function currentSession(Request $request)
    {
        $authUser  = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);
        $cashRegId = $request->query('cash_register_id');

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Ambito de empresa invalido'], 403);
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
            return response()->json(['message' => 'Validacion fallida', 'errors' => $validator->errors()], 422);
        }

        $payload   = $validator->validated();
        $companyId = (int) ($payload['company_id'] ?? $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Ambito de empresa invalido'], 403);
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
            return response()->json(['message' => 'Validacion fallida', 'errors' => $validator->errors()], 422);
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

        // Obtener desglose de ventas por tipo de pago (solo documentos tributarios)
        // Los documentos se obtienen a través de cash_movements
            $salesByPaymentMethod = DB::table('sales.cash_movements as cm')
                ->join('sales.commercial_documents as cd', 'cd.id', '=', 'cm.ref_id')
                ->leftJoin('master.payment_types as pm', 'pm.id', '=', 'cd.payment_method_id')
                ->select([
                    DB::raw('COALESCE(pm.id, 0) as payment_method_id'),
                    DB::raw("COALESCE(NULLIF(TRIM(pm.comment), ''), CONCAT('PM', pm.id::text), 'SIN_METODO') as payment_method_code"),
                    DB::raw("COALESCE(pm.name, 'Sin método de pago') as payment_method_name"),
                DB::raw('COUNT(cd.id) as document_count'),
                DB::raw('SUM(cd.total) as total_amount'),
            ])
            ->where('cm.cash_session_id', $id)
            ->where('cm.company_id', $companyId)
            ->whereIn('cm.ref_type', ['INVOICE', 'RECEIPT', 'COMMERCIAL_DOCUMENT'])
            ->where('cd.status', '!=', 'CANCELED')
                ->groupBy(DB::raw('COALESCE(pm.id, 0)'), DB::raw("COALESCE(NULLIF(TRIM(pm.comment), ''), CONCAT('PM', pm.id::text), 'SIN_METODO')"), DB::raw("COALESCE(pm.name, 'Sin método de pago')"))
                ->orderBy(DB::raw("COALESCE(NULLIF(TRIM(pm.comment), ''), CONCAT('PM', pm.id::text), 'SIN_METODO')"))
            ->get();

        DB::table('sales.cash_sessions')->where('id', $id)->update([
            'closed_at'        => now(),
            'closed_by'        => $authUser->id,
            'closing_balance'  => round((float) $payload['closing_balance'], 4),
            'expected_balance' => $expectedBalance,
            'status'           => 'CLOSED',
            'notes'            => $payload['notes'] ?? $session->notes,
        ]);

        $updated = DB::table('sales.cash_sessions')->where('id', $id)->first();

        // Formatear desglose de ventas por tipo de pago
        $paymentMethodBreakdown = [];
        foreach ($salesByPaymentMethod as $record) {
            $paymentMethodBreakdown[] = [
                'payment_method_id'   => (int) $record->payment_method_id,
                'payment_method_code' => $record->payment_method_code,
                'payment_method_name' => $record->payment_method_name,
                'document_count'      => (int) $record->document_count,
                'total_amount'        => round((float) $record->total_amount, 4),
            ];
        }

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
            'sales_by_payment_method' => $paymentMethodBreakdown,
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
            return response()->json(['message' => 'Ambito de empresa invalido'], 403);
        }

        $query = DB::table('sales.cash_movements as cm')
            ->leftJoin('auth.users as u', 'u.id', '=', DB::raw('COALESCE(cm.user_id, cm.created_by)'))
                ->leftJoin('sales.commercial_documents as cd', function ($join) {
                    $join->on('cd.id', '=', 'cm.ref_id')
                         ->whereIn('cm.ref_type', ['INVOICE', 'RECEIPT', 'COMMERCIAL_DOCUMENT']);
                })
                ->leftJoin('master.payment_types as pm', 'pm.id', '=', 'cd.payment_method_id')
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
                    DB::raw("pm.name as payment_method_name"),
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

    public function sessionDetail(Request $request, $id)
    {
        $authUser  = $request->attributes->get('auth_user');
        $companyId = (int) $authUser->company_id;

        // Obtener sesión
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
            ])
            ->where('cs.id', $id)
            ->where('cs.company_id', $companyId)
            ->first();

        if (!$session) {
            return response()->json(['message' => 'Sesion no encontrada'], 404);
        }

        // Calcular totales IN/OUT
        $totalIn  = (float) DB::table('sales.cash_movements')
            ->where('cash_session_id', $id)
            ->whereIn('movement_type', ['IN', 'INCOME'])
            ->sum('amount');

        $totalOut = (float) DB::table('sales.cash_movements')
            ->where('cash_session_id', $id)
            ->whereIn('movement_type', ['OUT', 'EXPENSE'])
            ->sum('amount');

        // Obtener movimientos de la sesión
        $rawMovements = DB::table('sales.cash_movements as cm')
            ->leftJoin('auth.users as u', 'u.id', '=', DB::raw('COALESCE(cm.user_id, cm.created_by)'))
            ->select([
                'cm.id',
                DB::raw("CASE WHEN cm.movement_type IN ('IN','INCOME') THEN 'IN' ELSE 'OUT' END as movement_type"),
                'cm.amount',
                DB::raw('COALESCE(cm.description, cm.notes) as description'),
                'cm.ref_type',
                'cm.ref_id',
                DB::raw("CONCAT(u.first_name, ' ', u.last_name) as user_name"),
                'cm.movement_at',
            ])
            ->where('cm.cash_session_id', $id)
            ->where('cm.company_id', $companyId)
            ->orderBy('cm.movement_at')
            ->get();

        $movements = array_map(function ($m) {
            return [
                'id'            => (int) $m->id,
                'movement_type' => $m->movement_type,
                'amount'        => round((float) $m->amount, 2),
                'description'   => $m->description,
                'ref_type'      => $m->ref_type,
                'ref_id'        => $m->ref_id,
                'user_name'     => $m->user_name,
                'movement_at'   => $m->movement_at,
            ];
        }, $rawMovements->toArray());

        // Obtener comprobantes vendidos en esta sesión (sales.customers, no core.customers)
        $documents = DB::table('sales.commercial_documents as cd')
            ->join('sales.cash_movements as cm', function ($join) use ($id) {
                $join->on('cd.id', '=', 'cm.ref_id')
                    ->where('cm.cash_session_id', $id)
                    ->whereIn('cm.ref_type', ['INVOICE', 'RECEIPT', 'COMMERCIAL_DOCUMENT']);
            })
            ->leftJoin('sales.customers as cust', 'cust.id', '=', 'cd.customer_id')
            ->leftJoin('master.payment_types as pm', 'pm.id', '=', 'cd.payment_method_id')
            ->leftJoin('auth.users as u_doc', 'u_doc.id', '=', 'cd.created_by')
            ->select([
                'cd.id',
                'cd.document_kind',
                DB::raw("COALESCE((SELECT dk.label FROM sales.document_kinds dk WHERE dk.id = cd.document_kind_id LIMIT 1), (SELECT dk2.label FROM sales.document_kinds dk2 WHERE UPPER(dk2.code) = UPPER(cd.document_kind) LIMIT 1), cd.document_kind) as document_kind_label"),
                'cd.series',
                'cd.number',
                DB::raw("CONCAT(cd.series, '-', cd.number) as document_number"),
                DB::raw("COALESCE(cust.legal_name, cust.trade_name, cust.first_name, '-') as customer_name"),
                'pm.name as payment_method_name',
                'cd.total',
                'cd.status',
                'cd.created_at',
                DB::raw("CONCAT(u_doc.first_name, ' ', u_doc.last_name) as user_name"),
            ])
            ->where('cd.company_id', $companyId)
            ->where('cd.status', '!=', 'CANCELED')
            ->orderBy('cd.created_at')
            ->get();

        // Para cada documento, obtener sus líneas (items tienen description propia, unidades en core.units)
        $documentsWithItems = [];
        foreach ($documents as $doc) {
            $items = DB::table('sales.commercial_document_items as cdi')
                ->leftJoin('core.units as u', 'u.id', '=', 'cdi.unit_id')
                ->leftJoin('inventory.products as p', function ($join) use ($companyId) {
                    $join->on('p.id', '=', 'cdi.product_id')
                        ->where('p.company_id', '=', $companyId);
                })
                ->select([
                    'cdi.id',
                    'cdi.product_id',
                    'cdi.description',
                    'cdi.qty',
                    DB::raw('COALESCE(u.code, \'-\') as unit_code'),
                    'cdi.unit_price',
                    'cdi.unit_cost',
                    'p.cost_price as product_cost_price',
                    'cdi.total as line_total',
                ])
                ->where('cdi.document_id', $doc->id)
                ->orderBy('cdi.line_no')
                ->get();

            $documentsWithItems[] = [
                'id'                  => (int) $doc->id,
                'document_number'     => $doc->document_number,
                'document_kind'       => $doc->document_kind,
                'document_kind_label' => $doc->document_kind_label,
                'customer_name'       => $doc->customer_name,
                'payment_method_name' => $doc->payment_method_name,
                'total'               => round((float) $doc->total, 2),
                'status'              => $doc->status,
                'created_at'          => $doc->created_at,
                'user_name'           => $doc->user_name,
                'items'               => array_map(function ($item) {
                    $qty = round((float) $item->qty, 3);
                    $unitPrice = round((float) $item->unit_price, 2);
                    $lineTotal = round((float) $item->line_total, 2);
                    $costMeta = $this->resolveItemCostAndMargin(
                        $qty,
                        $lineTotal,
                        $unitPrice,
                        isset($item->unit_cost) ? (float) $item->unit_cost : null,
                        isset($item->product_cost_price) ? (float) $item->product_cost_price : null
                    );

                    return [
                        'product_id'   => $item->product_id ? (int) $item->product_id : null,
                        'description' => $item->description,
                        'quantity'    => $qty,
                        'unit_code'   => $item->unit_code,
                        'unit_price'  => $unitPrice,
                        'line_total'  => $lineTotal,
                        'unit_cost'   => $costMeta['unit_cost'],
                        'cost_total'  => $costMeta['cost_total'],
                        'margin_total' => $costMeta['margin_total'],
                        'margin_percent' => $costMeta['margin_percent'],
                        'margin_source' => $costMeta['margin_source'],
                    ];
                }, $items->toArray()),
            ];
        }

        // Desglose de ventas por tipo de pago
        $salesByPaymentMethod = DB::table('sales.cash_movements as cm')
            ->join('sales.commercial_documents as cd', 'cd.id', '=', 'cm.ref_id')
                ->leftJoin('master.payment_types as pm', 'pm.id', '=', 'cd.payment_method_id')
                ->select([
                    DB::raw('COALESCE(pm.id, 0) as payment_method_id'),
                        DB::raw("COALESCE(NULLIF(TRIM(pm.comment), ''), CONCAT('PM', pm.id::text), 'SIN_METODO') as payment_method_code"),
                    DB::raw("COALESCE(pm.name, 'Sin método de pago') as payment_method_name"),
                DB::raw('COUNT(cd.id) as document_count'),
                DB::raw('SUM(cd.total) as total_amount'),
            ])
            ->where('cm.cash_session_id', $id)
            ->where('cm.company_id', $companyId)
            ->whereIn('cm.ref_type', ['INVOICE', 'RECEIPT', 'COMMERCIAL_DOCUMENT'])
            ->where('cd.status', '!=', 'CANCELED')
                ->groupBy(DB::raw('COALESCE(pm.id, 0)'), DB::raw("COALESCE(NULLIF(TRIM(pm.comment), ''), CONCAT('PM', pm.id::text), 'SIN_METODO')"), DB::raw("COALESCE(pm.name, 'Sin método de pago')"))
                ->orderBy(DB::raw("COALESCE(NULLIF(TRIM(pm.comment), ''), CONCAT('PM', pm.id::text), 'SIN_METODO')"))
            ->get();

        $paymentMethodBreakdown = array_map(function ($record) {
            return [
                'payment_method_id'   => (int) $record->payment_method_id,
                'payment_method_code' => $record->payment_method_code,
                'payment_method_name' => $record->payment_method_name,
                'document_count'      => (int) $record->document_count,
                'total_amount'        => round((float) $record->total_amount, 2),
            ];
        }, $salesByPaymentMethod->toArray());

        return response()->json([
            'session' => [
                'id'                 => (int) $session->id,
                'cash_register_code' => $session->cash_register_code,
                'cash_register_name' => $session->cash_register_name,
                'user_name'          => $session->user_name,
                'opened_at'          => $session->opened_at,
                'closed_at'          => $session->closed_at,
                'opening_balance'    => round((float) $session->opening_balance, 2),
                'closing_balance'    => $session->closing_balance ? round((float) $session->closing_balance, 2) : null,
                'expected_balance'   => round((float) $session->expected_balance, 2),
                'status'             => $session->status,
                'notes'              => $session->notes,
            ],
            'summary' => [
                'total_in'   => round($totalIn, 2),
                'total_out'  => round($totalOut, 2),
                'difference' => $session->closing_balance
                    ? round((float) $session->closing_balance - ((float) $session->opening_balance + $totalIn - $totalOut), 2)
                    : null,
            ],
            'movements'                => $movements,
            'documents'                => $documentsWithItems,
            'payment_method_breakdown' => $paymentMethodBreakdown,
        ]);
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
            return response()->json(['message' => 'Validacion fallida', 'errors' => $validator->errors()], 422);
        }

        $payload   = $validator->validated();
        $companyId = (int) ($payload['company_id'] ?? $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Ambito de empresa invalido'], 403);
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

    private function resolveItemCostAndMargin(
        float $qty,
        float $lineTotal,
        float $unitPrice,
        ?float $itemUnitCost,
        ?float $productCostPrice
    ): array {
        $qtySafe = $qty > 0 ? $qty : 0.0;
        $lineTotalSafe = max(0.0, $lineTotal);

        $realUnitCost = null;
        if ($itemUnitCost !== null && $itemUnitCost > 0) {
            $realUnitCost = $itemUnitCost;
        } elseif ($productCostPrice !== null && $productCostPrice > 0) {
            $realUnitCost = $productCostPrice;
        }

        if ($realUnitCost !== null) {
            $costTotal = $qtySafe > 0 ? $realUnitCost * $qtySafe : 0.0;
            $marginTotal = $lineTotalSafe - $costTotal;
            $marginPercent = $lineTotalSafe > 0 ? ($marginTotal / $lineTotalSafe) * 100 : 0.0;

            return [
                'unit_cost' => round($realUnitCost, 4),
                'cost_total' => round($costTotal, 2),
                'margin_total' => round($marginTotal, 2),
                'margin_percent' => round($marginPercent, 2),
                'margin_source' => 'REAL',
            ];
        }

        // Fallback conservador para items sin costo trazable:
        // usa un margen objetivo controlado para evitar sobreestimar ganancias.
        $estimatedMarginRate = 0.22;
        $maxEstimatedMarginRate = 0.35;

        $targetMargin = $lineTotalSafe * $estimatedMarginRate;
        $maxMargin = $lineTotalSafe * $maxEstimatedMarginRate;
        $marginTotal = min(max(0.0, $targetMargin), max(0.0, $maxMargin));
        $costTotal = max(0.0, $lineTotalSafe - $marginTotal);

        $referenceUnitPrice = $qtySafe > 0 ? ($lineTotalSafe / $qtySafe) : max(0.0, $unitPrice);
        $estimatedUnitCost = $qtySafe > 0 ? ($costTotal / $qtySafe) : ($referenceUnitPrice * (1 - $estimatedMarginRate));
        $marginPercent = $lineTotalSafe > 0 ? ($marginTotal / $lineTotalSafe) * 100 : 0.0;

        return [
            'unit_cost' => round(max(0.0, $estimatedUnitCost), 4),
            'cost_total' => round($costTotal, 2),
            'margin_total' => round($marginTotal, 2),
            'margin_percent' => round($marginPercent, 2),
            'margin_source' => 'ESTIMATED',
        ];
    }

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
