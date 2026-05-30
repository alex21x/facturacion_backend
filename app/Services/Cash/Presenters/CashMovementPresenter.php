<?php

namespace App\Services\Cash\Presenters;

class CashMovementPresenter
{
    public function presentMovement(object $movement): array
    {
        return [
            'id' => (int) $movement->id,
            'cash_register_id' => (int) $movement->cash_register_id,
            'cash_session_id' => $movement->cash_session_id !== null ? (int) $movement->cash_session_id : null,
            'movement_type' => (string) $movement->movement_type,
            'amount' => $movement->amount,
            'description' => $movement->description,
            'ref_type' => $movement->ref_type,
            'ref_id' => $movement->ref_id,
            'document_number' => $movement->document_number,
            'document_kind_label' => $movement->document_kind_label,
            'reference_label' => $this->buildReferenceLabel($movement),
            'user_id' => $movement->user_id,
            'user_name' => $this->buildActorLabel($movement),
            'movement_at' => $movement->movement_at,
            'payment_method_name' => $movement->payment_method_name,
        ];
    }

    private function buildActorLabel(object $movement): string
    {
        $issuer = trim((string) ($movement->issuer_user_name ?? ''));
        $seller = trim((string) ($movement->origin_seller_user_name ?? ''));
        $fallbackUser = trim((string) ($movement->user_name ?? ''));

        $actorLabel = $fallbackUser;
        if ($seller !== '' && $issuer !== '' && strtoupper($seller) !== strtoupper($issuer)) {
            return 'Solicita: ' . $seller . ' | Emite: ' . $issuer;
        }
        if ($issuer !== '') {
            return $issuer;
        }
        if ($seller !== '') {
            return $seller;
        }

        return $actorLabel;
    }

    private function buildReferenceLabel(object $movement): ?string
    {
        if (!$movement->document_number) {
            return null;
        }

        $kindLabel = trim((string) ($movement->document_kind_label ?? 'Comprobante'));
        $referenceLabel = $kindLabel . ' ' . $movement->document_number;

        $sourceNumber = trim((string) ($movement->source_document_number ?? ''));
        $sourceKindLabel = trim((string) ($movement->source_document_kind_label ?? ''));
        if ($sourceNumber !== '' && $sourceKindLabel !== '') {
            return $sourceKindLabel . ' ' . $sourceNumber . ' -> ' . $referenceLabel;
        }

        return $referenceLabel;
    }
}
