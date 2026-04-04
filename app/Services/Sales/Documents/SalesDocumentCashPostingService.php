<?php

namespace App\Services\Sales\Documents;

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

        $firstPaidMethod = collect($payments)->first(function ($payment) {
            return ($payment['status'] ?? 'PENDING') === 'PAID';
        });

        $labelMap = ['INVOICE' => 'Factura', 'RECEIPT' => 'Boleta', 'CREDIT_NOTE' => 'Nota Credito', 'DEBIT_NOTE' => 'Nota Debito', 'QUOTATION' => 'Cotizacion', 'SALES_ORDER' => 'Pedido'];
        $description = 'Cobro doc ' . ($labelMap[$documentKind] ?? $documentKind) . ' ' . $series . '-' . $number;

        DB::table('sales.cash_movements')->insert([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'cash_register_id' => $cashRegisterId,
            'cash_session_id' => (int) $session->id,
            'movement_type' => 'INCOME',
            'payment_method_id' => $firstPaidMethod['payment_method_id'] ?? null,
            'amount' => round($paidTotal, 4),
            'description' => $description,
            'notes' => $description,
            'ref_type' => 'COMMERCIAL_DOCUMENT',
            'ref_id' => $documentId,
            'created_by' => $userId,
            'user_id' => $userId,
            'movement_at' => now(),
            'created_at' => now(),
        ]);

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
