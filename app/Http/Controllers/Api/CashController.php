<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cash\CloseCashSessionRequest;
use App\Http\Requests\Cash\CreateCashMovementRequest;
use App\Http\Requests\Cash\OpenCashSessionRequest;
use App\Http\Requests\Cash\UpdateCashMovementRequest;
use App\Services\Cash\CashMovementService;
use App\Services\Cash\CashSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashController extends Controller
{
    public function __construct(
        private CashSessionService $cashSessionService,
        private CashMovementService $cashMovementService
    ) {
    }

    private const DOCUMENT_MOVEMENT_REF_TYPES = ['INVOICE', 'RECEIPT', 'COMMERCIAL_DOCUMENT', 'SALES_ORDER'];
    private const EXCLUDED_DOCUMENT_STATUSES = ['CANCELED', 'VOID', 'VOIDED'];
    private const SALES_ORDER_MULTI_PAYMENT_FEATURE_CODE = 'SALES_ORDER_MULTI_PAYMENT_ENABLED';

    public function sessions(Request $request)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $cashRegId = $request->query('cash_register_id');
        $status = $request->query('status');
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('per_page', $request->query('limit', 10));

        if ($page < 1) {
            $page = 1;
        }
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 100) {
            $limit = 100;
        }

        $cashRegisterId = ($cashRegId !== null && $cashRegId !== '') ? (int) $cashRegId : null;
        $statusFilter = ($status !== null && $status !== '') ? (string) $status : null;

        return response()->json($this->cashSessionService->paginateSessions($companyId, $cashRegisterId, $statusFilter, $page, $limit));
    }

    public function currentSession(Request $request)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $cashRegId = $request->query('cash_register_id');

        $cashRegisterId = ($cashRegId !== null && $cashRegId !== '') ? (int) $cashRegId : null;

        return response()->json([
            'session' => $this->cashSessionService->findCurrentOpenSession($companyId, $cashRegisterId),
        ]);
    }

    public function openSession(OpenCashSessionRequest $request)
    {
        $authUser = $request->attributes->get('auth_user');

        $payload = $request->validated();
        $companyId = (int) $request->attributes->get('resolved_company_id');

        $existing = $this->cashSessionService->findOpenSessionByRegister($companyId, (int) $payload['cash_register_id']);

        if ($existing) {
            return response()->json([
                'message' => 'La caja ya tiene una sesion abierta',
                'session_id' => $existing->id,
            ], 409);
        }

        $openingBalance = round((float) $payload['opening_balance'], 4);

        $sessionId = $this->cashSessionService->createSession([
            'company_id' => $companyId,
            'branch_id' => $authUser->branch_id ?? null,
            'cash_register_id' => (int) $payload['cash_register_id'],
            'opened_by' => $authUser->id,
            'user_id' => $authUser->id,
            'opened_at' => now(),
            'opening_balance' => $openingBalance,
            'expected_balance' => $openingBalance,
            'status' => 'OPEN',
            'notes' => $payload['notes'] ?? null,
            'created_at' => now(),
        ]);

        $session = $this->cashSessionService->findSessionById($sessionId);

        return response()->json(['message' => 'Sesion de caja abierta', 'session' => $session], 201);
    }

    public function closeSession(CloseCashSessionRequest $request, $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');

        $session = $this->cashSessionService->findSessionByIdAndCompany((int) $id, (int) $companyId);

        if (!$session) {
            return response()->json(['message' => 'Sesion no encontrada'], 404);
        }

        if ($session->status !== 'OPEN') {
            return response()->json(['message' => 'La sesion no esta abierta'], 409);
        }

        $payload = $request->validated();

        $this->ensureSessionCommercialDocumentMovements((int) $companyId, (int) $session->id);

        $totalIn = $this->cashSessionService->sumSessionIncome((int) $id, self::DOCUMENT_MOVEMENT_REF_TYPES, self::EXCLUDED_DOCUMENT_STATUSES);
        $totalOut = $this->cashSessionService->sumSessionExpense((int) $id, self::DOCUMENT_MOVEMENT_REF_TYPES, self::EXCLUDED_DOCUMENT_STATUSES);
        $expectedBalance = round((float) $session->opening_balance + $totalIn - $totalOut, 4);
        $paymentMethodBreakdown = $this->cashSessionService->listSessionSalesByPaymentMethod((int) $id, (int) $companyId, self::DOCUMENT_MOVEMENT_REF_TYPES, self::EXCLUDED_DOCUMENT_STATUSES);

        $this->cashSessionService->closeSession(
            (int) $id,
            (int) $authUser->id,
            round((float) $payload['closing_balance'], 4),
            $expectedBalance,
            $payload['notes'] ?? $session->notes
        );

        $updated = $this->cashSessionService->findSessionById((int) $id);

        return response()->json([
            'message' => 'Sesion de caja cerrada',
            'session' => $updated,
            'summary' => [
                'opening_balance' => (float) $session->opening_balance,
                'total_in' => $totalIn,
                'total_out' => $totalOut,
                'expected_balance' => $expectedBalance,
                'closing_balance' => round((float) $payload['closing_balance'], 4),
                'difference' => round((float) $payload['closing_balance'] - $expectedBalance, 4),
            ],
            'sales_by_payment_method' => $paymentMethodBreakdown,
        ]);
    }

    public function movements(Request $request)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $sessionId = $request->query('session_id');
        $cashRegId = $request->query('cash_register_id');
        $limit = min((int) $request->query('limit', 50), 200);

        $sessionId = ($sessionId !== null && $sessionId !== '') ? (int) $sessionId : null;
        $cashRegId = ($cashRegId !== null && $cashRegId !== '') ? (int) $cashRegId : null;

        if ($sessionId !== null) {
            $this->ensureSessionCommercialDocumentMovements($companyId, $sessionId);
        }

        return response()->json([
            'data' => $this->cashMovementService->listMovements(
                $companyId,
                $sessionId,
                $cashRegId,
                $limit,
                self::DOCUMENT_MOVEMENT_REF_TYPES,
                self::EXCLUDED_DOCUMENT_STATUSES
            ),
        ]);
    }

    public function sessionDetail(Request $request, $id)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $sessionId = (int) $id;

        $session = $this->cashMovementService->findSessionDetail($companyId, $sessionId);

        if (!$session) {
            return response()->json(['message' => 'Sesion no encontrada'], 404);
        }

        $this->ensureSessionCommercialDocumentMovements($companyId, $sessionId);

        $totalIn = $this->cashSessionService->sumSessionIncome($sessionId, self::DOCUMENT_MOVEMENT_REF_TYPES, self::EXCLUDED_DOCUMENT_STATUSES);
        $totalOut = $this->cashSessionService->sumSessionExpense($sessionId, self::DOCUMENT_MOVEMENT_REF_TYPES, self::EXCLUDED_DOCUMENT_STATUSES);
        $movements = array_reverse(
            $this->cashMovementService->listMovements(
                $companyId,
                $sessionId,
                null,
                1000,
                self::DOCUMENT_MOVEMENT_REF_TYPES,
                self::EXCLUDED_DOCUMENT_STATUSES
            )
        );

        $salesOrderMultiPaymentEnabled = $this->isSalesOrderMultiPaymentEnabled($companyId);

        $documentsWithItems = [];
        foreach ($this->cashMovementService->listSessionCommercialDocuments($companyId, $sessionId, self::DOCUMENT_MOVEMENT_REF_TYPES, self::EXCLUDED_DOCUMENT_STATUSES) as $doc) {
            $documentItems = array_map(function ($item) {
                    $qty = round((float) data_get($item, 'qty', 0), 3);
                    $unitPrice = round((float) data_get($item, 'unit_price', 0), 2);
                    $lineSubtotal = round((float) data_get($item, 'line_subtotal', 0), 2);
                    $lineTotal = round((float) data_get($item, 'line_total', 0), 2);
                    $lineRevenueForMargin = $lineSubtotal > 0 ? $lineSubtotal : $lineTotal;
                    $costMeta = $this->resolveItemCostAndMargin(
                        $qty,
                        $lineRevenueForMargin,
                        $lineTotal,
                        $unitPrice,
                        data_get($item, 'unit_cost') !== null ? (float) data_get($item, 'unit_cost') : null,
                        data_get($item, 'product_cost_price') !== null ? (float) data_get($item, 'product_cost_price') : null
                    );

                    return [
                        'product_id' => data_get($item, 'product_id') ? (int) data_get($item, 'product_id') : null,
                        'description' => (string) data_get($item, 'description', ''),
                        'quantity' => $qty,
                        'unit_code' => (string) data_get($item, 'unit_code', ''),
                        'unit_price' => $unitPrice,
                        'line_subtotal' => $lineSubtotal,
                        'line_total' => $lineTotal,
                        'unit_cost' => $costMeta['unit_cost'],
                        'cost_total' => $costMeta['cost_total'],
                        'margin_total' => $costMeta['margin_total'],
                        'margin_percent' => $costMeta['margin_percent'],
                        'margin_total_net' => $costMeta['margin_total_net'],
                        'margin_percent_net' => $costMeta['margin_percent_net'],
                        'margin_total_commercial' => $costMeta['margin_total_commercial'],
                        'margin_percent_commercial' => $costMeta['margin_percent_commercial'],
                        'margin_source' => $costMeta['margin_source'],
                    ];
                }, $this->cashMovementService->listDocumentItems((int) $doc->id, $companyId));

            $baseDocument = [
                'id' => (int) $doc->id,
                'document_number' => $doc->document_number,
                'document_kind' => $doc->document_kind,
                'document_kind_label' => $doc->document_kind_label,
                'customer_name' => $doc->customer_name,
                'customer_vehicle_id' => $doc->customer_vehicle_id !== null ? (int) $doc->customer_vehicle_id : null,
                'vehicle_plate_snapshot' => $doc->vehicle_plate_snapshot !== null ? (string) $doc->vehicle_plate_snapshot : null,
                'vehicle_brand_snapshot' => $doc->vehicle_brand_snapshot !== null ? (string) $doc->vehicle_brand_snapshot : null,
                'vehicle_model_snapshot' => $doc->vehicle_model_snapshot !== null ? (string) $doc->vehicle_model_snapshot : null,
                'payment_method_name' => $doc->payment_method_name,
                'total' => round((float) $doc->total, 2),
                'status' => $doc->status,
                'created_at' => $doc->created_at,
                'user_name' => $doc->user_name,
                'items' => $documentItems,
            ];

            $paymentBreakdown = $salesOrderMultiPaymentEnabled && strtoupper((string) ($doc->document_kind ?? '')) === 'SALES_ORDER'
                ? $this->extractPaymentBreakdownFromMetadata($doc->metadata ?? null, (float) ($doc->total ?? 0))
                : [];

            if (count($paymentBreakdown) === 0) {
                $documentsWithItems[] = $baseDocument;
                continue;
            }

            $documentTotal = max(0.0, (float) ($doc->total ?? 0));
            foreach ($paymentBreakdown as $split) {
                $splitAmount = (float) ($split['amount'] ?? 0);
                $ratio = $documentTotal > 0 ? ($splitAmount / $documentTotal) : 0.0;

                $splitItems = array_map(function (array $itemRow) use ($ratio): array {
                    return [
                        ...$itemRow,
                        'quantity' => round((float) ($itemRow['quantity'] ?? 0), 3),
                        'line_subtotal' => round((float) ($itemRow['line_subtotal'] ?? 0) * $ratio, 2),
                        'line_total' => round((float) ($itemRow['line_total'] ?? 0) * $ratio, 2),
                        'cost_total' => round((float) ($itemRow['cost_total'] ?? 0) * $ratio, 2),
                        'margin_total' => round((float) ($itemRow['margin_total'] ?? 0) * $ratio, 2),
                        'margin_total_net' => round((float) ($itemRow['margin_total_net'] ?? 0) * $ratio, 2),
                        'margin_total_commercial' => round((float) ($itemRow['margin_total_commercial'] ?? 0) * $ratio, 2),
                    ];
                }, $documentItems);

                $documentsWithItems[] = [
                    ...$baseDocument,
                    'payment_method_name' => (string) ($split['payment_method_name'] ?? ($baseDocument['payment_method_name'] ?? '-')),
                    'total' => round($splitAmount, 2),
                    'items' => $splitItems,
                ];
            }
        }

        $paymentMethodBreakdown = $salesOrderMultiPaymentEnabled
            ? $this->buildPaymentBreakdownFromDocuments($documentsWithItems)
            : $this->cashSessionService->listSessionSalesByPaymentMethod($sessionId, $companyId, self::DOCUMENT_MOVEMENT_REF_TYPES, self::EXCLUDED_DOCUMENT_STATUSES);

        return response()->json([
            'session' => [
                'id' => (int) $session->id,
                'cash_register_code' => $session->cash_register_code,
                'cash_register_name' => $session->cash_register_name,
                'user_name' => $session->user_name,
                'opened_at' => $session->opened_at,
                'closed_at' => $session->closed_at,
                'opening_balance' => round((float) $session->opening_balance, 2),
                'closing_balance' => $session->closing_balance ? round((float) $session->closing_balance, 2) : null,
                'expected_balance' => round((float) $session->expected_balance, 2),
                'status' => $session->status,
                'notes' => $session->notes,
            ],
            'summary' => [
                'total_in' => round($totalIn, 2),
                'total_out' => round($totalOut, 2),
                'difference' => $session->closing_balance
                    ? round((float) $session->closing_balance - ((float) $session->opening_balance + $totalIn - $totalOut), 2)
                    : null,
            ],
            'movements' => $movements,
            'documents' => $documentsWithItems,
            'payment_method_breakdown' => $paymentMethodBreakdown,
        ]);
    }

    /**
     * Registrar movimiento manual de efectivo (entrada/salida).
     * Requiere: rbac.module:SALES,create
     *
     * Permisos:
     * - El usuario debe tener can_create=true en el módulo SALES en auth.role_module_access
     * - El perfil VENDER debe estar configurado con permiso para crear en SALES (movimientos, apertura/cierre de caja)
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createMovement(CreateCashMovementRequest $request)
    {
        $authUser = $request->attributes->get('auth_user');

        $payload = $request->validated();
        $companyId = (int) $request->attributes->get('resolved_company_id');

        $sessionId = isset($payload['cash_session_id']) ? (int) $payload['cash_session_id'] : null;

        if ($sessionId === null) {
            $openSession = $this->cashSessionService->findCurrentOpenSession($companyId, (int) $payload['cash_register_id']);
            $sessionId = $openSession?->id !== null ? (int) $openSession->id : null;
        }

        if ($sessionId === null) {
            return response()->json([
                'message' => 'No hay una sesion abierta para la caja indicada',
            ], 409);
        }

        $movementType = $this->toDbMovementType((string) $payload['movement_type']);

        $movementId = $this->cashMovementService->createMovement([
            'company_id' => $companyId,
            'branch_id' => $authUser->branch_id ?? null,
            'cash_register_id' => (int) $payload['cash_register_id'],
            'cash_session_id' => $sessionId,
            'movement_type' => $movementType,
            'amount' => round((float) $payload['amount'], 4),
            'notes' => $payload['description'],
            'description' => $payload['description'],
            'ref_type' => 'MANUAL',
            'ref_id' => null,
            'created_by' => $authUser->id,
            'user_id' => $authUser->id,
            'movement_at' => now(),
            'created_at' => now(),
        ]);

        $this->recalcExpectedBalance((int) $sessionId);

        $movement = $this->cashMovementService->findMovementById((int) $movementId);

        return response()->json(['message' => 'Movimiento registrado', 'movement' => $movement], 201);
    }

    public function updateMovement(UpdateCashMovementRequest $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');

        $movement = $this->cashMovementService->findMovementById($id);
        if (!$movement || (int) $movement->company_id !== $companyId) {
            return response()->json(['message' => 'Movimiento no encontrado'], 404);
        }

        $refType = strtoupper(trim((string) ($movement->ref_type ?? '')));
        if ($refType !== 'MANUAL' && $refType !== '') {
            return response()->json([
                'message' => 'Solo se pueden editar movimientos manuales.',
            ], 422);
        }

        if ((int) ($movement->cash_session_id ?? 0) > 0) {
            $session = $this->cashSessionService->findSessionById((int) $movement->cash_session_id);
            if (!$session || !$this->isCashSessionOpenStatus((string) ($session->status ?? ''))) {
                return response()->json([
                    'message' => 'Solo se pueden editar movimientos de sesiones abiertas.',
                ], 422);
            }
        }

        $payload = $request->validated();
        $movementType = $this->toDbMovementType((string) $payload['movement_type']);

        $this->cashMovementService->updateMovementById($companyId, $id, [
            'movement_type' => $movementType,
            'amount' => round((float) $payload['amount'], 4),
            'description' => $payload['description'],
            'notes' => $payload['description'],
            'updated_at' => now(),
            'user_id' => $authUser->id,
        ]);

        if ((int) ($movement->cash_session_id ?? 0) > 0) {
            $this->recalcExpectedBalance((int) $movement->cash_session_id);
        }

        $updated = $this->cashMovementService->findMovementById((int) $id);

        return response()->json([
            'message' => 'Movimiento actualizado',
            'movement' => $updated,
        ]);
    }

    private function ensureSessionCommercialDocumentMovements(int $companyId, int $sessionId): void
    {
        $session = $this->cashMovementService->findSessionScopeForCommercialDocuments($companyId, $sessionId);

        if (!$session || !$session->cash_register_id) {
            return;
        }

        $documents = $this->cashMovementService->listSyncSessionCommercialDocuments(
            $companyId,
            $sessionId,
            ['SALES_ORDER', 'INVOICE', 'RECEIPT', 'DEBIT_NOTE', 'CREDIT_NOTE']
        );

        if (empty($documents)) {
            return;
        }

        $hasSyncChanges = false;

        foreach ($documents as $document) {
            if ($this->cashMovementService->upsertSessionCommercialDocumentMovement($companyId, $sessionId, (object) $document, $session)) {
                $hasSyncChanges = true;
            }
        }

        if ($hasSyncChanges) {
            $this->recalcExpectedBalance((int) $session->id);
        }
    }

    private function resolveItemCostAndMargin(
        float $qty,
        float $lineRevenue,
        float $lineTotal,
        float $unitPrice,
        ?float $itemUnitCost,
        ?float $productCostPrice
    ): array {
        $qtySafe = $qty > 0 ? $qty : 0.0;
        $lineRevenueSafe = max(0.0, $lineRevenue);
        $lineTotalSafe = max(0.0, $lineTotal);
        $commercialCostFactor = $lineRevenueSafe > 0 && $lineTotalSafe > 0
            ? max(1.0, $lineTotalSafe / $lineRevenueSafe)
            : 1.0;

        $unitCostNet = null;
        $unitCostCommercial = null;

        if ($itemUnitCost !== null && $itemUnitCost > 0) {
            $unitCostNet = $itemUnitCost;
            $unitCostCommercial = $unitCostNet * $commercialCostFactor;
        } elseif ($productCostPrice !== null && $productCostPrice > 0) {
            $unitCostCommercial = $productCostPrice;
            $unitCostNet = $commercialCostFactor > 0
                ? $unitCostCommercial / $commercialCostFactor
                : $unitCostCommercial;
        }

        if ($unitCostNet !== null && $unitCostCommercial !== null) {
            $costTotalNet = $qtySafe > 0 ? $unitCostNet * $qtySafe : 0.0;
            $costTotalCommercial = $qtySafe > 0 ? $unitCostCommercial * $qtySafe : 0.0;
            $marginNet = $lineRevenueSafe - $costTotalNet;
            $marginNetPct = $lineRevenueSafe > 0 ? ($marginNet / $lineRevenueSafe) * 100 : 0.0;
            $marginCommercial = $lineTotalSafe - $costTotalCommercial;
            $marginCommercialPct = $lineTotalSafe > 0 ? ($marginCommercial / $lineTotalSafe) * 100 : 0.0;

            return [
                'unit_cost' => round($unitCostCommercial, 4),
                'cost_total' => round($costTotalCommercial, 2),
                'margin_total' => round($marginNet, 2),
                'margin_percent' => round($marginNetPct, 2),
                'margin_total_net' => round($marginNet, 2),
                'margin_percent_net' => round($marginNetPct, 2),
                'margin_total_commercial' => round($marginCommercial, 2),
                'margin_percent_commercial' => round($marginCommercialPct, 2),
                'margin_source' => 'REAL',
            ];
        }

        $marginNet = $lineRevenueSafe;
        $marginNetPct = $lineRevenueSafe > 0 ? 100.0 : 0.0;
        $marginCommercial = $lineTotalSafe;
        $marginCommercialPct = $lineTotalSafe > 0 ? 100.0 : 0.0;

        return [
            'unit_cost' => 0.0,
            'cost_total' => 0.0,
            'margin_total' => round($marginNet, 2),
            'margin_percent' => round($marginNetPct, 2),
            'margin_total_net' => round($marginNet, 2),
            'margin_percent_net' => round($marginNetPct, 2),
            'margin_total_commercial' => round($marginCommercial, 2),
            'margin_percent_commercial' => round($marginCommercialPct, 2),
            'margin_source' => 'REAL',
        ];
    }

    private function isSalesOrderMultiPaymentEnabled(int $companyId): bool
    {
        return DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->whereRaw('UPPER(feature_code) = ?', [self::SALES_ORDER_MULTI_PAYMENT_FEATURE_CODE])
            ->where('is_enabled', true)
            ->exists();
    }

    private function extractPaymentBreakdownFromMetadata($metadataRaw, float $documentTotal): array
    {
        $metadata = [];
        if (is_array($metadataRaw)) {
            $metadata = $metadataRaw;
        } elseif (is_string($metadataRaw) && trim($metadataRaw) !== '') {
            $decoded = json_decode($metadataRaw, true);
            $metadata = is_array($decoded) ? $decoded : [];
        }

        $rows = is_array($metadata['payment_breakdown'] ?? null) ? $metadata['payment_breakdown'] : [];
        $normalized = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $amount = round((float) ($row['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }

            $name = trim((string) ($row['payment_method_name'] ?? $row['method_name'] ?? $row['name'] ?? ''));
            if ($name === '') {
                $methodId = isset($row['payment_method_id']) ? (int) $row['payment_method_id'] : 0;
                $name = $methodId > 0 ? ('Metodo #' . $methodId) : 'Metodo de pago';
            }

            $normalized[] = [
                'payment_method_name' => $name,
                'amount' => $amount,
            ];
        }

        if (count($normalized) <= 1) {
            return [];
        }

        $totalBreakdown = array_reduce($normalized, static fn (float $carry, array $row): float => $carry + (float) $row['amount'], 0.0);
        if ($documentTotal <= 0 || $totalBreakdown <= 0) {
            return [];
        }

        // Normalize split total to document total to avoid rounding drift in UI totals.
        $factor = $documentTotal / $totalBreakdown;
        foreach ($normalized as &$row) {
            $row['amount'] = round((float) $row['amount'] * $factor, 2);
        }
        unset($row);

        return $normalized;
    }

    private function buildPaymentBreakdownFromDocuments(array $documents): array
    {
        $grouped = [];

        foreach ($documents as $doc) {
            $name = trim((string) ($doc['payment_method_name'] ?? ''));
            if ($name === '') {
                $name = 'Sin método de pago';
            }

            if (!isset($grouped[$name])) {
                $grouped[$name] = [
                    'payment_method_id' => 0,
                    'payment_method_code' => strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $name) ?? 'PM', 0, 12)) ?: 'PM',
                    'payment_method_name' => $name,
                    'document_ids' => [],
                    'total_amount' => 0.0,
                ];
            }

            $grouped[$name]['document_ids'][(int) ($doc['id'] ?? 0)] = true;
            $grouped[$name]['total_amount'] += (float) ($doc['total'] ?? 0);
        }

        $rows = [];
        foreach ($grouped as $entry) {
            $rows[] = [
                'payment_method_id' => $entry['payment_method_id'],
                'payment_method_code' => $entry['payment_method_code'],
                'payment_method_name' => $entry['payment_method_name'],
                'document_count' => count($entry['document_ids']),
                'total_amount' => round((float) $entry['total_amount'], 2),
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return strcasecmp((string) ($a['payment_method_name'] ?? ''), (string) ($b['payment_method_name'] ?? ''));
        });

        return $rows;
    }

    private function recalcExpectedBalance(int $sessionId): void
    {
        $this->cashMovementService->recalcExpectedBalance($sessionId, self::DOCUMENT_MOVEMENT_REF_TYPES, self::EXCLUDED_DOCUMENT_STATUSES);
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

    private function isCashSessionOpenStatus(string $status): bool
    {
        $normalized = strtoupper(trim($status));

        return in_array($normalized, ['OPEN', 'OPENED', 'ACTIVE', 'ABIERTA'], true);
    }
}