# Facturación Codebase Research Summary

## Overview
This document contains detailed findings about quotations (cotizaciones) creation, document editing restrictions, direct sales mode configuration, and QUOTATION to SALES_ORDER conversion logic.

---

## 1. How Quotations Are Created and Where Dates Are Assigned

### Date Assignment Location
**File**: [app/Services/Sales/Documents/SalesDocumentCreationService.php](app/Services/Sales/Documents/SalesDocumentCreationService.php#L200-L209)

#### Date Resolution Process:
```php
// Line 200
$resolvedIssueAt = $this->resolveIssueAtForStorage($payload['issue_at'] ?? null);

// Line 209 - stored in database
'issue_at' => $resolvedIssueAt,
```

#### Date Resolution Logic (lines 387-398):
```php
private function resolveIssueAtForStorage($issueAt)
{
    if ($issueAt === null || $issueAt === '') {
        return now();  // Current timestamp if not provided
    }
    
    $text = trim((string) $issueAt);
    
    // If date-only format (YYYY-MM-DD), append current Lima time
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
        $limaNow = now('America/Lima');
        return $text . ' ' . $limaNow->format('H:i:s') . '-05:00';
    }
    
    return $issueAt;  // Use as-is if already has time component
}
```

### Key Points:
- **Default behavior**: Uses current timestamp if no date provided
- **Lima timezone aware**: Appends Lima timezone time if only date provided (YYYY-MM-DD format)
- **Stored field**: `commercial_documents.issue_at` (timestamp with timezone)
- **Creation entry point**: `SalesDocumentCreationService::create()` → `createFromCommand()`

### Related Issue Date for Summary:
```php
// Lines 311-424 - resolveIssueDateForSummary()
// Used for daily summary assignment - extracts date portion only
// Defaults to Lima timezone current date if not provided
```

---

## 2. Error Message: "La edicion no esta permitida para este comprobante en la configuracion actual"

### Error Location
**File**: [src/modules/sales/components/SalesView.tsx](src/modules/sales/components/SalesView.tsx#L3716-L3717)

```typescript
if (!canEditCommercialDocument(row, canEditDraftInCurrentMode, canEditIssuedBeforeSunatFinalInCurrentMode)) {
  setMessage('La edicion no esta permitida para este comprobante en la configuracion actual.');
  return;
}
```

### Error Trigger Conditions

**Function**: `canEditCommercialDocument()` (lines 772-789)

```typescript
function canEditCommercialDocument(
  row: CommercialDocumentListItem,
  allowDraftEdit: boolean,
  allowIssuedBeforeFinalSunatEdit: boolean
): boolean {
  const status = String(row.status ?? '').toUpperCase();

  // DRAFT documents: only if SALES_ALLOW_DRAFT_EDIT is enabled
  if (status === 'DRAFT') {
    return allowDraftEdit;
  }

  // ISSUED tributary documents: only if SALES_ALLOW_ISSUED_EDIT_BEFORE_SUNAT_FINAL enabled
  // AND SUNAT status is not final
  if (status === 'ISSUED' && isTributaryDocumentKind(row.document_kind)) {
    return allowIssuedBeforeFinalSunatEdit && !resolveSunatUiState(row).isFinal;
  }

  // All other cases: no editing allowed
  return false;
}
```

### Full Control State Resolution
**Function**: `resolveEditControlState()` (lines 795-839)

Checks extend to:
- QUOTATION/SALES_ORDER pre-documents: Can edit if `allowDraftEdit=true` AND no active conversions
- Active conversions block editing: "Documento ya convertido: no se puede editar"
- SUNAT final state blocks editing: "SUNAT en estado final: no se puede editar"

---

## 3. "Venta Directa" (Direct Sales) Mode Configuration

### Configuration Flag
**Feature Code**: `SALES_SELLER_TO_CASHIER`

### Alternative Names:
- When **disabled** (false) → "**Venta directa en punto de venta**" (Direct sales at POS)
- When **enabled** (true) → "**Vendedor → Caja independiente**" (Seller → Independent Cashier)

### Location in Code
**File**: [src/modules/sales/components/SalesView.tsx](src/modules/sales/components/SalesView.tsx#L1161-L1167)

```typescript
const sellerToCashierSource = featureSource(lookups?.commerce_features, 'SALES_SELLER_TO_CASHIER');

const salesFlowModeLabel = salesFlowMode === 'SELLER_TO_CASHIER'
  ? 'Vendedor -> Caja independiente'
  : 'Venta directa en punto de venta';
```

### Configuration Definition
**File**: [app/Http/Controllers/Api/AppConfigController.php](app/Http/Controllers/Api/AppConfigController.php#L1836)

```php
'SALES_SELLER_TO_CASHIER' => 'Vendedor: Flujo separado vendedor->caja'
```

### Backend Feature Flags
**File**: [app/Http/Controllers/Api/SalesController.php](app/Http/Controllers/Api/SalesController.php#L112-L119)

```php
'SALES_ALLOW_ISSUED_EDIT_BEFORE_SUNAT_FINAL' => true,
'SALES_ALLOW_DRAFT_EDIT' => true,
'SALES_ALLOW_DOCUMENT_VOID' => true,
'SALES_ALLOW_VOID_FOR_SELLER' => true,
'SALES_ALLOW_VOID_FOR_CASHIER' => true,
'SALES_ALLOW_VOID_FOR_ADMIN' => true,
```

### Direct Sales Mode Effects
**File**: [app/Services/Sales/Documents/SalesDocumentCreationService.php](app/Services/Sales/Documents/SalesDocumentCreationService.php#L76-L100)

When `SALES_SELLER_TO_CASHIER` is **disabled** (Direct Sales Mode):
```php
$isSellerToCashierMode = $this->isCommerceFeatureEnabledForContext(..., 'SALES_SELLER_TO_CASHIER');

if ($isSellerToCashierMode) {
    // Separate seller/cashier workflows
    if ($isPreDocument && $this->isCashierActor(...) && !$isConversionFlow) {
        throw new SalesDocumentException('En este modo, caja no genera pedidos. Use Reporte para convertir pedidos pendientes.');
    }
    
    if (!$isPreDocument && $this->isSellerActor(...)) {
        throw new SalesDocumentException('En este modo, vendedor no emite comprobantes finales. Debe generar pedido para caja.');
    }
}
```

When enabled, pre-documents (QUOTATION/SALES_ORDER) force DRAFT status and clear payments:
```php
if ($isSellerToCashierMode && $isPreDocument && !$isConversionFlow) {
    $payload['status'] = 'DRAFT';
    $payload['payments'] = [];
    $cashRegisterId = null;
}
```

---

## 4. QUOTATION to SALES_ORDER (Nota de Pedido) Conversion

### Conversion Endpoint
**File**: [app/Http/Controllers/Api/SalesController.php](app/Http/Controllers/Api/SalesController.php#L1783-L2177)

**Method**: `convertCommercialDocument(Request $request, $id)`

### Document Kind Mapping
```
QUOTATION (Cotización) → SALES_ORDER (Nota de Pedido)
                      → INVOICE (Factura)
                      → RECEIPT (Boleta)
```

### Conversion Restrictions

#### 1. Source Document Type Restriction (line 1829)
```php
if (!in_array((string) $source->document_kind, ['QUOTATION', 'SALES_ORDER'], true)) {
    return response()->json([
        'message' => 'Solo se puede convertir cotizacion o pedido de venta',
    ], 422);
}
```

#### 2. SELLER_TO_CASHIER Mode Restriction (lines 1835-1838)
```php
if ($this->isCommerceFeatureEnabledForContext($companyId, $sourceBranchId, 'SALES_SELLER_TO_CASHIER') 
    && !$this->isCashierActor($roleProfile, $roleCode)) {
    return response()->json([
        'message' => 'Solo caja puede convertir pedidos en este modo de venta.',
    ], 403);
}
```

#### 3. Document Status Restriction (lines 1843-1844)
```php
if (in_array((string) $source->status, ['VOID', 'CANCELED'], true)) {
    return response()->json([
        'message' => 'No se puede convertir un documento anulado/cancelado',
    ], 422);
}
```

#### 4. Same-Kind Conversion Prevention (lines 1857-1861)
```php
if ((string) $source->document_kind === 'SALES_ORDER' && $targetDocumentKind === 'SALES_ORDER') {
    return response()->json([
        'message' => 'El documento origen ya es una nota de pedido',
    ], 422);
}
```

#### 5. Double-Conversion Prevention (lines 1863-1870)
```php
$alreadyConverted = DB::table('sales.commercial_documents as d')
    ->where('d.company_id', $companyId)
    ->where('d.document_kind', $targetDocumentKind)
    ->whereNotIn('d.status', ['VOID', 'CANCELED'])
    ->whereRaw("COALESCE((d.metadata->>'source_document_id')::BIGINT, 0) = ?", [$sourceId])
    ->exists();

if ($alreadyConverted) {
    return response()->json([
        'message' => 'El documento ya fue convertido a ' . $targetDocumentKind,
    ], 409);
}
```

#### 6. Items Requirement (lines 1882-1883)
```php
if ($sourceItems->isEmpty()) {
    return response()->json([
        'message' => 'El documento origen no tiene items para convertir',
    ], 422);
}
```

### Conversion Process

#### Default Target Status
```php
$targetStatus = isset($payload['status']) && $payload['status'] !== null
    ? (string) $payload['status']
    : 'ISSUED';

// SALES_ORDER always converts to ISSUED
if ($targetDocumentKind === 'SALES_ORDER' && strtoupper($targetStatus) !== 'ISSUED') {
    $targetStatus = 'ISSUED';
}
```

#### Source Metadata Inheritance
```php
// Lines 1921-1930
'metadata' => array_merge($sourceMetadata, [
    'source_document_id' => $sourceId,
    'source_document_kind' => (string) $source->document_kind,
    'source_document_number' => $sourceNumber,
    'conversion_origin' => 'SALES_MODULE',
    'stock_already_discounted' => $sourceHadStockImpact,
]),
```

#### Items Preservation
- All item fields copied: product_id, unit_id, price_tier_id, tax_category_id, qty, unit_price, etc.
- Lot tracking preserved if source had lot assignments
- Invalid products (deleted/inactive) set to null but item preserved
- Line numbers and order maintained

---

## 5. Additional Editing Restrictions

### Enabled Configuration Flags
**File**: [app/Http/Controllers/Api/SalesController.php](app/Http/Controllers/Api/SalesController.php#L110-L119)

Available configuration controls:
- `SALES_ALLOW_ISSUED_EDIT_BEFORE_SUNAT_FINAL`: Allow editing ISSUED documents before SUNAT final state
- `SALES_ALLOW_DRAFT_EDIT`: Allow editing DRAFT documents (default: enabled)
- `SALES_ALLOW_DOCUMENT_VOID`: Allow voiding documents
- `SALES_ALLOW_VOID_FOR_SELLER`: Sellers can void documents
- `SALES_ALLOW_VOID_FOR_CASHIER`: Cashiers can void documents
- `SALES_ALLOW_VOID_FOR_ADMIN`: Admins can void documents

### Backend Update Validation
**File**: [app/Services/Sales/Documents/SalesDocumentUpdateService.php](app/Services/Sales/Documents/SalesDocumentUpdateService.php#L54-L80)

```php
$allowDraftEdit = $this->support->isCommerceFeatureEnabledForContextWithDefault(
    $companyId, 
    $featureBranchId, 
    'SALES_ALLOW_DRAFT_EDIT', 
    true  // default: enabled
);

// DRAFT status check
if ($documentStatus === 'DRAFT') {
    if (!$allowDraftEdit) {
        throw new SalesDocumentException('La edicion de borradores esta deshabilitada para este contexto.', 403);
    }
}

// Active conversion check
if ($this->support->hasActiveChildConversions($companyId, $documentId)) {
    throw new SalesDocumentException('No se puede editar: el documento ya tiene conversiones activas', 422);
}
```

---

## Database Schema

### Commercial Documents Table
- `issue_at`: timestamp with timezone (stores document creation/issue date)
- `document_kind`: enum (QUOTATION, SALES_ORDER, INVOICE, RECEIPT, CREDIT_NOTE, DEBIT_NOTE)
- `status`: varchar (DRAFT, APPROVED, ISSUED, VOID, CANCELED)
- `metadata`: jsonb (stores conversion_origin, source_document_id, sunat_status, etc.)

### Related Tables
- `sales.commercial_documents`: Main document table
- `sales.commercial_document_items`: Line items with lot tracking
- `sales.commercial_document_item_lots`: Lot assignments per item
- `sales.commercial_document_payments`: Payment records

---

## Summary of Key Files

| Purpose | File Path |
|---------|-----------|
| Date assignment logic | app/Services/Sales/Documents/SalesDocumentCreationService.php |
| Update restrictions | app/Services/Sales/Documents/SalesDocumentUpdateService.php |
| Conversion endpoint | app/Http/Controllers/Api/SalesController.php |
| Frontend error message | src/modules/sales/components/SalesView.tsx |
| Feature configuration | app/Http/Controllers/Api/AppConfigController.php |
| Document entity logic | app/Domain/Sales/Entities/CommercialDocumentEntity.php |
| Document policies | app/Domain/Sales/Policies/CommercialDocumentPolicy.php |
