<?php

namespace App\Infrastructure\Repositories\Cash;

use Illuminate\Database\Query\Builder;

final class CashPaymentMethodQuery
{
    public static function applyPhysicalCashFilter(Builder $query): Builder
    {
        $patterns = self::patterns();
        if ($patterns === []) {
            return $query->whereNull('cm.payment_method_id');
        }

        return $query->where(function (Builder $cashMovements) use ($patterns): void {
            $cashMovements
                ->whereNull('cm.payment_method_id')
                ->orWhereExists(function (Builder $paymentMethods) use ($patterns): void {
                    $paymentMethods
                        ->selectRaw('1')
                        ->from('master.payment_types as physical_cash_pm')
                        ->whereColumn('physical_cash_pm.id', 'cm.payment_method_id')
                        ->where(function (Builder $names) use ($patterns): void {
                            foreach ($patterns as $pattern) {
                                $names->orWhereRaw(
                                    "LOWER(COALESCE(physical_cash_pm.name, '')) LIKE ?",
                                    ['%' . $pattern . '%']
                                );
                            }
                        });
                });
        });
    }

    /**
     * @return array{0: string, 1: array<int, string>}
     */
    public static function physicalCashCase(string $paymentMethodIdColumn, string $paymentMethodNameColumn): array
    {
        $patterns = self::patterns();
        $nameConditions = array_fill(
            0,
            count($patterns),
            "LOWER(COALESCE({$paymentMethodNameColumn}, '')) LIKE ?"
        );
        $bindings = array_map(
            static fn (string $pattern): string => '%' . $pattern . '%',
            $patterns
        );
        $namePredicate = $nameConditions === []
            ? '1 = 0'
            : '(' . implode(' OR ', $nameConditions) . ')';

        return [
            "CASE WHEN {$paymentMethodIdColumn} IS NULL OR {$namePredicate} THEN TRUE ELSE FALSE END",
            $bindings,
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function patterns(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $pattern): string => mb_strtolower(trim((string) $pattern)),
            config('cash.physical_cash_payment_method_name_keywords', [])
        )));
    }
}
