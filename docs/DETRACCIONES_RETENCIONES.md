# Detracciones & Retenciones - Guía de Configuración

## 1. Feature Toggles (Habilitación por Empresa/Sucursal)

### Códigos de Feature Toggles

```
SALES_DETRACCION_ENABLED              → Detracciones en facturas de venta
SALES_RETENCION_ENABLED               → Retención de IGV en facturas de venta
PURCHASES_DETRACCION_ENABLED          → Detracciones en compras
PURCHASES_RETENCION_COMPRADOR_ENABLED → Retención al comprador (nosotros retenemos)
PURCHASES_RETENCION_PROVEEDOR_ENABLED → Retención del proveedor (proveedor retiene)
```

### Cómo Configurar

**A nivel de EMPRESA:**
```sql
INSERT INTO appcfg.company_feature_toggles 
  (company_id, feature_code, is_enabled, created_at, updated_at)
VALUES
  (1, 'SALES_DETRACCION_ENABLED', 1, NOW(), NOW()),
  (1, 'SALES_RETENCION_ENABLED', 1, NOW(), NOW()),
  (1, 'PURCHASES_DETRACCION_ENABLED', 1, NOW(), NOW()),
  (1, 'PURCHASES_RETENCION_COMPRADOR_ENABLED', 1, NOW(), NOW());
```

**A nivel de SUCURSAL (override empresa):**
```sql
INSERT INTO appcfg.branch_feature_toggles 
  (company_id, branch_id, feature_code, is_enabled, created_at, updated_at)
VALUES
  (1, 10, 'SALES_DETRACCION_ENABLED', 0, NOW(), NOW()); -- Desactiva detracciones aquí
```

## 2. Tablas Maestras

### `master.detraccion_service_codes`
- 31 códigos SUNAT (Catálogo 54)
- Campos: `code`, `name`, `rate_percent`, `is_active`
- Autogenerada en migración

**Ejemplo:**
```
001 | Azúcar y melaza de caña        | 10.00%
004 | Recursos hidrobiológicos      | 4.00%
030 | Contratos de construcción     | 4.00%
```

## 3. Flujo de Validación

### VENTAS (Sales)
1. **lookups()** - Retorna detracciones/retenciones SOLO si están habilitadas
2. **createCommercialDocument()** - Valida:
   - ✅ Feature toggle activo
   - ✅ Document kind = INVOICE
   - ✅ Código de servicio válido
   - ✅ Calcula montos automáticamente

### COMPRAS (Purchases) - PRÓXIMO
- Mismo patrón + retencia de comprador Y proveedor

## 4. Metadata en Documento

```json
{
  "has_detraccion": true,
  "detraccion_service_code": "030",
  "detraccion_service_name": "Contratos de construcción",
  "detraccion_rate_percent": 4.00,
  "detraccion_amount": 400.00,
  
  "has_retencion": true,
  "retencion_rate_percent": 3.00,
  "retencion_amount": 300.00
}
```

## 5. Frontend - Comportamiento Inteligente

- Detracciones/retenciones **SOLO aparecen si están habilitadas**
- Dropdown de códigos se llena desde `lookups.detraccion_service_codes[]`
- Montos se calculan en tiempo real
- Validación frontend + backend

## 6. SUNAT Compliance

✅ Almacena todos los datos en metadata  
✅ Soporta múltiples detracciones/retenciones por documento  
✅ Huellas de auditoría automáticas  
✅ Listo para UBL/XML generation  

---

## PRÓXIMOS PASOS

1. **Purchases Module** - Agregar detracciones/retenciones a compras
2. **Shipments Integration** - Puente para guías de remisión
3. **SUNAT Bridge** - XML generation y envío tributario
