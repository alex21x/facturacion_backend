<?php

namespace App\Domain\Sales\Policies;

use DomainException;

final class CommercialDocumentPolicy
{
    public static function shouldAffectStock(string $documentKind, string $status): bool
    {
        return strtoupper($status) === 'ISSUED' && in_array(strtoupper($documentKind), [
            'SALES_ORDER',
            'INVOICE',
            'RECEIPT',
            'DEBIT_NOTE',
            'CREDIT_NOTE',
        ], true);
    }

    public static function stockDirectionForDocument(string $documentKind): int
    {
        $kind = strtoupper($documentKind);

        if (in_array($kind, ['SALES_ORDER', 'INVOICE', 'RECEIPT', 'DEBIT_NOTE'], true)) {
            return -1;
        }

        return $kind === 'CREDIT_NOTE' ? 1 : 0;
    }

    public static function selectedTaxConditions(array $metadata): int
    {
        return (!empty($metadata['has_detraccion']) ? 1 : 0)
            + (!empty($metadata['has_retencion']) ? 1 : 0)
            + (!empty($metadata['has_percepcion']) ? 1 : 0);
    }

    public static function assertSingleTaxCondition(array $metadata): void
    {
        if (self::selectedTaxConditions($metadata) > 1) {
            throw new DomainException('Solo puede aplicar una condicion tributaria entre detraccion, retencion o percepcion por comprobante.');
        }
    }
}
