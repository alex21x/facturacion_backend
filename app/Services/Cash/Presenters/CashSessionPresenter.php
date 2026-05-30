<?php

namespace App\Services\Cash\Presenters;

class CashSessionPresenter
{
    public function presentSalesByPaymentMethod(object $record): array
    {
        return [
            'payment_method_id' => (int) $record->payment_method_id,
            'payment_method_code' => (string) $record->payment_method_code,
            'payment_method_name' => (string) $record->payment_method_name,
            'document_count' => (int) $record->document_count,
            'total_amount' => round((float) $record->total_amount, 4),
        ];
    }
}
