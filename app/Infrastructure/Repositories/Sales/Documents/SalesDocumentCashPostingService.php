<?php

namespace App\Infrastructure\Repositories\Sales\Documents;

use Illuminate\Support\Facades\DB;

class SalesDocumentCashPostingService
{
    public function registerCashIncomeFromDocument(
        int $companyId,
        ?int $branchId,
        ?int $cashRegisterId,
        int $documentId,
        string $documentKind,
        string $series,
        int $number,
        float $paidTotal,
        int $userId,
        array $payments = []
    ): void {
        if ($cashRegisterId === null || $paidTotal <= 0) {
            return;
        }

        if (!$this->tableExists('sales.cash_sessions') || !$this->tableExists('sales.cash_movements')) {
            return;
        }

        $session = DB::table('sales.cash_sessions')
            ->where('company_id', $companyId)
            ->where('cash_register_id', $cashRegisterId)
            ->where('status', 'OPEN')
            ->orderByDesc('opened_at')
            ->first();

        if (!$session) {
            return;
        }

        $alreadyPosted = DB::table('sales.cash_movements')
            ->where('company_id', $companyId)
            ->where('cash_session_id', (int) $session->id)
            ->where('ref_type', 'COMMERCIAL_DOCUMENT')
            ->where('ref_id', $documentId)
            ->whereIn('movement_type', ['IN', 'INCOME'])
            ->exists();

        if ($alreadyPosted) {
            return;
        }

        $paidBreakdown = collect($payments)
            ->filter(function ($payment) {
                $status = strtoupper(trim((string) ($payment['status'] ?? 'PENDING')));
                $amount = isset($payment['amount']) ? (float) $payment['amount'] : 0.0;
                return $status === 'PAID' && $amount > 0;
            })
            ->map(function ($payment) {
                return [
                    'payment_method_id' => isset($payment['payment_method_id']) ? (int) $payment['payment_method_id'] : null,
                    'amount' => round((float) $payment['amount'], 4),
                ];
            })
            ->groupBy(function ($row) {
                return $row['payment_method_id'] ?? 'null';
            })
            ->map(function ($group) {
                $first = $group->first();
                return [
                    'payment_method_id' => $first['payment_method_id'],
                    'amount' => round((float) $group->sum('amount'), 4),
                ];
            })
            ->values()
            ->filter(fn ($row) => (float) $row['amount'] > 0)
            ->all();

        if (empty($paidBreakdown)) {
            $paidBreakdown = [[
                'payment_method_id' => null,
                'amount' => round($paidTotal, 4),
            ]];
        }

        $labelMap = ['INVOICE' => 'Factura', 'RECEIPT' => 'Boleta', 'CREDIT_NOTE' => 'Nota Credito', 'DEBIT_NOTE' => 'Nota Debito', 'QUOTATION' => 'Cotizacion', 'SALES_ORDER' => 'Pedido'];
        $description = 'Cobro doc ' . ($labelMap[$documentKind] ?? $documentKind) . ' ' . $series . '-' . $number;

        $movementAt = now();
        $rowsToInsert = [];
        foreach ($paidBreakdown as $paymentRow) {
            $rowsToInsert[] = [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'cash_register_id' => $cashRegisterId,
                'cash_session_id' => (int) $session->id,
                'movement_type' => 'INCOME',
                'payment_method_id' => $paymentRow['payment_method_id'],
                'amount' => (float) $paymentRow['amount'],
                'description' => $description,
                'notes' => $description,
                'ref_type' => 'COMMERCIAL_DOCUMENT',
                'ref_id' => $documentId,
                'created_by' => $userId,
                'user_id' => $userId,
                'movement_at' => $movementAt,
                'created_at' => $movementAt,
            ];
        }

        DB::table('sales.cash_movements')->insert($rowsToInsert);

        $totalIn = (float) DB::table('sales.cash_movements')
            ->where('cash_session_id', (int) $session->id)
            ->whereIn('movement_type', ['IN', 'INCOME'])
            ->sum('amount');

        $totalOut = (float) DB::table('sales.cash_movements')
            ->where('cash_session_id', (int) $session->id)
            ->whereIn('movement_type', ['OUT', 'EXPENSE'])
            ->sum('amount');

        DB::table('sales.cash_sessions')
            ->where('id', (int) $session->id)
            ->update([
                'expected_balance' => round((float) $session->opening_balance + $totalIn - $totalOut, 4),
            ]);
    }

    private function tableExists(string $qualifiedTable): bool
    {
        [$schema, $table] = strpos($qualifiedTable, '.') === false ? ['public', $qualifiedTable] : explode('.', $qualifiedTable, 2);
        $row = DB::selectOne('select exists (select 1 from information_schema.tables where table_schema = ? and table_name = ?) as present', [$schema, $table]);

        return isset($row->present) && (bool) $row->present;
    }
}
