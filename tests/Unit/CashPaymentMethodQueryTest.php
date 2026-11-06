<?php

namespace Tests\Unit;

use App\Infrastructure\Repositories\Cash\CashPaymentMethodQuery;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CashPaymentMethodQueryTest extends TestCase
{
    public function test_physical_cash_filter_only_accepts_configured_cash_method_names(): void
    {
        config([
            'cash.physical_cash_payment_method_name_keywords' => [
                'efect',
                'cash',
                'contado',
            ],
        ]);

        $query = DB::table('sales.cash_movements as cm');
        CashPaymentMethodQuery::applyPhysicalCashFilter($query);

        $this->assertStringContainsString('exists', strtolower($query->toSql()));
        $this->assertSame(['%efect%', '%cash%', '%contado%'], $query->getBindings());
    }

    public function test_breakdown_marks_missing_payment_method_as_physical_cash(): void
    {
        config([
            'cash.physical_cash_payment_method_name_keywords' => ['efect'],
        ]);

        [$expression, $bindings] = CashPaymentMethodQuery::physicalCashCase(
            'pm.id',
            'pm.name'
        );

        $this->assertStringContainsString('pm.id IS NULL', $expression);
        $this->assertSame(['%efect%'], $bindings);
    }
}
