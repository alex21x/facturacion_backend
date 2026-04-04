<?php

namespace App\Domain\Sales\Entities;

use App\Domain\Sales\Policies\CommercialDocumentPolicy;
use App\Domain\Sales\ValueObjects\DocumentStatus;
use DomainException;

final class CommercialDocumentEntity
{
    private int $id;
    private int $companyId;
    private ?int $branchId;
    private ?int $warehouseId;
    private string $documentKind;
    private DocumentStatus $status;
    private ?string $notes;
    private array $metadata;

    private function __construct(
        int $id,
        int $companyId,
        ?int $branchId,
        ?int $warehouseId,
        string $documentKind,
        DocumentStatus $status,
        ?string $notes,
        array $metadata
    ) {
        $this->id = $id;
        $this->companyId = $companyId;
        $this->branchId = $branchId;
        $this->warehouseId = $warehouseId;
        $this->documentKind = strtoupper(trim($documentKind));
        $this->status = $status;
        $this->notes = $notes;
        $this->metadata = $metadata;
    }

    public static function fromPersistence(object $row): self
    {
        $metadata = [];
        if (isset($row->metadata)) {
            if (is_array($row->metadata)) {
                $metadata = $row->metadata;
            } else {
                $decoded = json_decode((string) $row->metadata, true);
                $metadata = is_array($decoded) ? $decoded : [];
            }
        }

        return new self(
            (int) $row->id,
            (int) $row->company_id,
            $row->branch_id !== null ? (int) $row->branch_id : null,
            $row->warehouse_id !== null ? (int) $row->warehouse_id : null,
            (string) $row->document_kind,
            new DocumentStatus((string) $row->status),
            isset($row->notes) ? (string) $row->notes : null,
            $metadata
        );
    }

    public function id(): int
    {
        return $this->id;
    }

    public function branchId(): ?int
    {
        return $this->branchId;
    }

    public function status(): string
    {
        return $this->status->value();
    }

    public function notes(): ?string
    {
        return $this->notes;
    }

    public function metadata(): array
    {
        return $this->metadata;
    }

    public function assertCanEditDraft(): void
    {
        if ($this->status->isClosed()) {
            throw new DomainException('No se puede editar un documento anulado/cancelado');
        }

        if (!$this->status->isDraft()) {
            throw new DomainException('Solo se permite editar documentos en estado borrador');
        }
    }

    public function assertCanVoid(): void
    {
        if ($this->status->isClosed()) {
            throw new DomainException('El documento ya se encuentra anulado/cancelado');
        }
    }

    public function shouldAffectStockOnCurrentState(): bool
    {
        return CommercialDocumentPolicy::shouldAffectStock($this->documentKind, $this->status->value());
    }

    public function buildVoidMetadata(array $payload, int $voidedBy, bool $inventoryEnabled, bool $inventoryReverted): array
    {
        $metadata = $this->metadata;
        $metadata['inventory_void_reverted'] = $inventoryReverted;
        $metadata['inventory_void_reverse_enabled'] = $inventoryEnabled;
        $metadata['void_reason'] = isset($payload['reason']) ? (string) $payload['reason'] : null;
        $metadata['void_notes'] = isset($payload['notes']) ? (string) $payload['notes'] : null;
        $metadata['voided_by'] = $voidedBy;
        $metadata['voided_at'] = isset($payload['void_at']) ? (string) $payload['void_at'] : now()->toDateTimeString();

        if (isset($payload['sunat_void_status']) && trim((string) $payload['sunat_void_status']) !== '') {
            $metadata['sunat_void_status'] = trim((string) $payload['sunat_void_status']);
        }

        return $metadata;
    }
}
