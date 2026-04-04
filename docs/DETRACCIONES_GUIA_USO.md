# Guía Completa: Detracciones & Retenciones en Ventas + Compras

## 1. Configuración Rápida

### Paso 1: Habilitar en tu Empresa

```sql
-- Conectar a: facturacion
-- Para empresa_id = 1

INSERT INTO appcfg.company_feature_toggles 
  (company_id, feature_code, is_enabled, created_at, updated_at)
VALUES
  (1, 'SALES_DETRACCION_ENABLED', 1, NOW(), NOW()),
  (1, 'SALES_RETENCION_ENABLED', 1, NOW(), NOW()),
  (1, 'PURCHASES_DETRACCION_ENABLED', 1, NOW(), NOW()),
  (1, 'PURCHASES_RETENCION_COMPRADOR_ENABLED', 1, NOW(), NOW()),
  (1, 'PURCHASES_RETENCION_PROVEEDOR_ENABLED', 1, NOW(), NOW());
```

### Paso 2: Override por Sucursal (Opcional)

```sql
-- Desactivar detracciones en sucursal 5
INSERT INTO appcfg.branch_feature_toggles 
  (company_id, branch_id, feature_code, is_enabled, created_at, updated_at)
VALUES (1, 5, 'SALES_DETRACCION_ENABLED', 0, NOW(), NOW());
```

---

## 2. Cómo Usar en VENTAS

### Registrar Factura con Detracción

1. **Selecciona documento**: INVOICE (Factura)
2. **Si está habilitado** → Verás: ☑ "Sujeto a detracción (SPOT)"
3. **Marca el checkbox** → Aparece dropdown con códigos SUNAT
4. **Selecciona código**: "030 - Contratos de construcción - 4%"
5. **Sistema calcula**: Factura total × 4% = Detracción
6. **En "Resumen tributario"** → Se muestra el monto detraído
7. **Guarda** → Todo queda en metadata del documento

### Datos Almacenados

```json
{
  "document_kind": "INVOICE",
  "total": 10000.00,
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

---

## 3. Cómo Usar en COMPRAS

### Registrar Entrada de Compra con Retención

1. **Selecciona**: "Compra (ingreso)"
2. **Referencia**: "OC-001" o factura del proveedor
3. **Proveedor**: Nombre/RUC
4. **Si está habilitado**:
   - ☑ "Retención al Comprador" → Nosotros retenemos al proveedor  
   - ☑ "Retención del Proveedor" → Proveedor nos retiene

5. **Marca opción** → Sistema calcula automáticamente
6. **Monto retención** = Costo total × 3%

### Tipos de Retención en Compras

| Tipo | Significado | Quién Retiene |
|------|-------------|---------------|
| **Retención al Comprador** | Nosotros retenemos al proveedor | Nosotros |
| **Retención del Proveedor** | Proveedor nos retiene a nosotros | Proveedor |

---

## 4. Códigos SUNAT (Catálogo 54)

| Código | Descripción | % |
|--------|-------------|-----|
| **001** | Azúcar y melaza de caña | 10.00% |
| **004** | Recursos hidrobiológicos | 4.00% |
| **005** | Maíz amarillo duro | 4.00% |
| **006** | Arena y piedra | 10.00% |
| **030** | Contratos de construcción | 4.00% |
| **037** | Otros servicios empresariales | 12.00% |
| **047** | Demás servicios gravados IGV | 12.00% |

**Total**: 31 códigos disponibles

---

## 5. Feature Toggles Disponibles

### VENTAS

| Toggle Code | Descripción |
|------------|------------|
| `SALES_DETRACCION_ENABLED` | Permite marcar "Sujeto a detracción" en facturas |
| `SALES_RETENCION_ENABLED` | Permite marcar "Sujeto a retención de IGV" |

### COMPRAS

| Toggle Code | Descripción |
|------------|------------|
| `PURCHASES_DETRACCION_ENABLED` | Detracciones en entradas de compra |
| `PURCHASES_RETENCION_COMPRADOR_ENABLED` | Retención que nosotros hacemos al proveedor |
| `PURCHASES_RETENCION_PROVEEDOR_ENABLED` | Retención que el proveedor nos hace |

---

## 6. Arquitectura Técnica

### Base de Datos

#### Master Table
```sql
-- master.detraccion_service_codes
id (PK)
code (VARCHAR(4)) - "030", "001", etc.
name (VARCHAR(300))
rate_percent (NUMERIC(5,2))
is_active (SMALLINT)
```

#### Configuración
```sql
-- appcfg.company_feature_toggles
company_id (FK)
feature_code (VARCHAR)
is_enabled (SMALLINT)

-- appcfg.branch_feature_toggles (optional override)
company_id (FK)
branch_id (FK)
feature_code (VARCHAR)
is_enabled (SMALLINT)
```

#### Metadata en Documentos
```sql
-- sales.commercial_documents.metadata → JSONB
{
  "has_detraccion": true,
  "detraccion_service_code": "030",
  "detraccion_service_name": "...",
  "detraccion_rate_percent": 4.00,
  "detraccion_amount": 400.00,
  
  "has_retencion": true,
  "retencion_rate_percent": 3.00,
  "retencion_amount": 300.00
}
```

---

## 7. Flujo de Validación

### SalesController.lookups()
```
1. ¿Está SALES_DETRACCION_ENABLED? 
   → SÍ: Retorna lista de detracciones
   → NO: Retorna array vacío

2. ¿Está SALES_RETENCION_ENABLED?
   → SÍ: Retorna retencion_percentage (3.00)
   → NO: Retorna null
```

### SalesController.createCommercialDocument()
```
1. Si has_detraccion && document_kind != 'INVOICE' 
   → ERROR: "Detracción solo en Facturas"

2. Si has_detraccion && !toggle_enabled
   → ERROR: "Detracción no habilitada para esta empresa"

3. Si has_detraccion && codigo_invalido
   → ERROR: "Código de detracción inválido"

4. Si todo OK:
   → Calcula: total × rate_percent / 100
   → Guarda en metadata
   → Inserta documento con montos enriquecidos
```

---

## 8. Frontend: Comportamiento Inteligente

### SalesView

```tsx
// Solo muestra detracciones/retenciones si están habilitadas
{isInvoiceDocument && 
  ((lookups?.detraccion_service_codes?.length > 0) || 
   (lookups?.retencion_percentage !== null)) && (
  <>
    {/* Detracciones */}
    {lookups?.detraccion_service_codes?.length > 0 && (
      <checkbox>Sujeto a detracción</checkbox>
    )}
    
    {/* Retenciones */}
    {lookups?.retencion_percentage && (
      <checkbox>Sujeto a retención</checkbox>
    )}
  </>
)}
```

### PurchasesView (Próximo)

```tsx
// Similar a SalesView pero:
// - Solo en "PURCHASE" (no "ADJUSTMENT")
// - Dos opciones de retención: comprador + proveedor
```

---

## 9. Integración SUNAT (Roadmap)

### Datos Listos para UBL 2.1

El sistema **ya guarda** todos los datos necesarios para generar XML:

```xml
<ext:RetainedTaxRatePercentage>4.00</ext:RetainedTaxRatePercentage>
<ext:RetainedTaxAmount>400.00</ext:RetainedTaxAmount>
<ext:DetectionCode>30</ext:DetectionCode>
```

### Próximos Pasos When Integrating SUNAT

1. Leer metadata de `commercial_documents`
2. Extraer `detraccion_amount`, `retencion_amount`
3. Usar template UBL para XML generation
4. Enviar a SUNAT via API/SFTP
5. Almacenar status de tributación (CDR)

---

## 10. Troubleshooting

### "¿Por qué no veo el checkbox de detracción?"

1. Verifica que esté habilitado:
```sql
SELECT * FROM appcfg.company_feature_toggles 
WHERE feature_code = 'SALES_DETRACCION_ENABLED' 
  AND company_id = 1;
```

2. Si no existe → Inserta como arriba (Paso 1)
3. Recarga el frontend (F5)

### "¿El monto se calcula diferente?"

- Sistema: `Total × rate_percent / 100`
- Redondea a 2 decimales
- Ejemplo: S/. 10,000 × 4% = S/. 400.00

### "¿Puedo cambiar la tasa de retención (3%)?"

Actualmente: **3.00% fija** (estándar SUNAT IGV)

Futura mejora: Hacer configurable por toggles

---

## 11. Casos de Uso Reales

### Caso 1: Constructor (Detracción 4%)

```
Factura: S/. 50,000
Detracción obligatoria: 4%
Monto detraído: S/. 2,000

→ Declarar en SPOT ante SUNAT
→ Banco retiene S/. 2,000 automático
→ Constructor recibe S/. 48,000
```

### Caso 2: Comercio de Pescado (Detracción 4%)

```
Factura: S/. 30,000
Bien: Recursos hidrobiológicos
Detracción: S/. 1,200

→ Obligatorio en documento
→ Afecta caja/flujo de efectivo
```

### Caso 3: Servicios (Posible Detracción 12%)

```
Factura: S/. 10,000  
Servicio: "Otros servicios empresariales"
Retención IGV: 3% = S/. 300
Detracción: 12% = S/. 1,200

→ Total retenciones: S/. 1,500
→ Cliente recibe: S/. 8,500
```

---

## 12. Seguridad & Auditoría

### Quién Vio Qué

- `created_by` → User ID de quien creó
- `created_at` → Timestamp exacto
- `metadata` → Datos completos de detracciones/retenciones
- `status` → Si fue ISSUED/VOID/CANCELED

### Validaciones Multi-Capas

✅ **Frontend**: Valida antes de enviar  
✅ **API**: Valida toggles, tipos de documento, códigos  
✅ **Database**: Constraints de integridad  
✅ **Metadata**: Datos enriquecidos guardados

---

## 13. Próximos Pasos

1. **Habilitar toggles** (SQL arriba)
2. **Probar en VENTAS** (Factura + detracción)
3. **Implementar UI en COMPRAS** (Stock entry)
4. **Conectar SUNAT bridge** (XML + envío tributario)
5. **GRE/Shipments** (Guías de remisión)
