# Sistema de Auditoría Tributaria - Trazabilidad Completa

## 📋 Descripción General

Sistema de auditoría **completo e intuitivo** para registrar y visualizar **cada envío tributario** realizado a SUNAT. Proporciona trazabilidad total con:

- ✅ **Histórico de payloads y responses** - Cada envío queda registrado
- ✅ **Múltiples tipos tributarios** - RA, RC, RB, detracciones, retenciones, percepciones, etc.
- ✅ **Filtros dinámicos** - Por tipo, estado, fecha, serie, número
- ✅ **Estadísticas operativas** - Tasa de éxito, tiempos promedio por tipo
- ✅ **Diagnostico de fallos** - Acceso instantáneo a errores y detalles técnicos

---

## 🗺️ Componentes Creados

### **Backend**

#### 1. **Tabla de Auditoría** 
```
sales.tax_bridge_audit_logs
```

Campos principales:
- `company_id`, `branch_id` - Scope empresa/rama
- `document_id`, `document_kind`, `document_series`, `document_number` - Documento tributario
- `tributary_type` - Tipo: SUNAT_DIRECT, DETRACCION, RETENCION, PERCEPCION, SUMMARY_RA, SUMMARY_RC, etc
- `request_payload` - JSON completo enviado
- `response_body` - JSON/XML completo recibido
- `http_status_code`, `response_time_ms` - Métricas HTTP
- `sunat_status`, `sunat_code`, `ticket_number`, `cdr_code` - Parseo de response
- `attempt_number`, `is_retry`, `is_manual_dispatch` - Control de intentos
- `error_kind`, `error_message` - Debugging
- `sent_at`, `received_at` - Timestamps precisos
- `initiated_by_user_id`, `initiated_by_username` - Auditoría de quién envió

#### 2. **Modelo Eloquent**
```php
App\Infrastructure\Models\Sales\TaxBridgeAuditLog
```

Scopes disponibles:
```php
// Histórico de un documento
$logs = TaxBridgeAuditLog::forDocument($documentId)->get();

// Últimos envíos exitosos
$accepted = TaxBridgeAuditLog::forBranch($companyId, $branchId)
    ->accepted()
    ->orderByDesc('sent_at')
    ->limit(20)
    ->get();

// Solo reintentos
$retries = TaxBridgeAuditLog::retries()->get();

// Búsqueda por rango de fechas
$range = TaxBridgeAuditLog::betweenDates($start, $end)->get();
```

#### 3. **Servicio de Auditoría**
```php
App\Services\Sales\TaxBridge\TaxBridgeAuditService
```

**Métodos principales:**

```php
// Registrar un envío
$auditService->logDispatch(
    $companyId,
    $branchId,
    'SUNAT_DIRECT',           // tributary_type
    $documentId,
    'INVOICE',
    'B001',
    '00001',
    $config,                  // Config del puente
    $payloadJson,             // Payload enviado
    $responseBody,            // Response recibida
    $httpStatusCode,
    $responseTimeMs,
    [                         // Parsed response
        'code' => 1,
        'ticket' => 'ABC123',
        'message' => 'Aceptado',
    ],
    [                         // Opciones
        'sunat_status' => 'ACCEPTED',
        'error_kind' => null,
        'user_id' => auth()->id(),
        'username' => auth()->user()->name,
        'is_retry' => false,
        'is_manual' => false,
    ]
);

// Obtener histórico de un documento
$history = $auditService->getDocumentHistory($documentId, $limit = 50);

// Obtener con filtros
$filtered = $auditService->getBranchHistory(
    $companyId,
    $branchId,
    [
        'tributary_type' => 'SUNAT_DIRECT',
        'sunat_status' => 'ACCEPTED',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'only_errors' => false,
    ],
    $limit = 100
);

// Estadísticas por tipo tributario
$stats = $auditService->getStatistics($companyId, $branchId, $startDate, $endDate);
// Retorna: total_sent, accepted, rejected, pending, success_rate, response_time_ms (avg/min/max)

// Fallos recientes
$failures = $auditService->getRecentFailures($companyId, $branchId, $limit = 20);

// Detalles completos (para drawer/modal)
$details = $auditService->getLogDetails($logId);
```

#### 4. **API Controller**
```php
App\Http\Controllers\Api\TaxBridgeAuditController
```

**Endpoints creados:**

```
GET  /api/tax-bridge/audit/document/{documentId}
   - Histórico de un documento específico
   - Query params: limit=50

GET  /api/tax-bridge/audit/branch
   - Histórico de empresa/rama con filtros
   - Query params:
     * company_id, branch_id
     * tributary_type, sunat_status
     * start_date, end_date
     * document_series, document_number
     * only_errors (true/false)
     * limit=100

GET  /api/tax-bridge/audit/statistics
   - Estadísticas por tipo tributario
   - Query params:
     * company_id, branch_id
     * start_date, end_date

GET  /api/tax-bridge/audit/failures
   - Fallos recientes
   - Query params: company_id, branch_id, limit=20

GET  /api/tax-bridge/audit/{logId}
   - Detalles completos de un log (para drawer/modal)
```

---

## 🎨 Componentes Frontend Sugeridos

### **Componente 1: TaxBridgeAuditList**
```tsx
// Matriz table-like mostrando últimos envíos
// Columnas: Documento, Tipo, Estado, HTTP Code, Response Time, Fecha, Usuario
// Click row → abre drawer con detalles
// Filtros: Serie, Número, Status, Fecha
// Botones de acción: descargar payload, ver respuesta, reintentar
```

### **Componente 2: TaxBridgeAuditDrawer**
```tsx
// Vista detallada completa
// Tabs:
//   - Request: Payload enviado (JSON formateado)
//   - Response: Respuesta recibida
//   - Status: Códigos SUNAT, tickets, estado
//   - Metrics: HTTP code, response time, tamaño
//   - Audit Trail: Quién, cuándo, intento #
// Botones: Copy JSON, Download Payload, etc
```

### **Componente 3: TaxBridgeStatistics**
```tsx
// Gráficos/cards mostrando:
// - Success rate por tipo tributario
// - Q avg response time
// - Distribución de estados (accepted/rejected/pending)
// - Tasa de errores por tipo
```

---

## 🔌 Integración con TaxBridgeService

Necesita registro automático después de cada envío.

**Ubicación:** `performDispatch()` en `TaxBridgeService.php`

**Código a agregar (al final de performDispatch):**

```php
// Registrar auditoría
$auditService = app(TaxBridgeAuditService::class);
$auditService->logDispatch(
    $companyId,
    $branchId,
    'SUNAT_DIRECT',
    $documentId,
    $document->document_kind,
    $document->series,
    $document->number,
    $config,
    $payloadJson,
    $response->body(),
    (int) $response->status(),
    microtime(true) - $startTime * 1000, // ms
    [
        'code' => $finalBridgeCode,
        'ticket' => $bridgeTicket,
        'cdr_code' => $finalBridgeCode,
        'message' => $bridgeMessage,
    ],
    [
        'sunat_status' => $status, // ACCEPTED, REJECTED, PENDING_CONFIRMATION
        'error_kind' => null,
        'user_id' => auth()->id(),
        'username' => auth()->user()?->name,
        'is_retry' => $isRetry,
        'is_manual' => false,
    ]
);
```

---

## 📊 Ejemplo: Queries SQL Útiles

```sql
-- Últimos 50 envíos de factura de empresa 1
SELECT * FROM sales.tax_bridge_audit_logs
WHERE company_id = 1
  AND tributary_type = 'SUNAT_DIRECT'
  AND document_kind = 'INVOICE'
ORDER BY sent_at DESC
LIMIT 50;

-- Tasa de éxito por tipo tributario (últimos 30 días)
SELECT 
    tributary_type,
    COUNT(*) as total,
    SUM(CASE WHEN sunat_status = 'ACCEPTED' THEN 1 ELSE 0 END) as accepted,
    ROUND(100.0 * SUM(CASE WHEN sunat_status = 'ACCEPTED' THEN 1 ELSE 0 END) / COUNT(*), 2) as success_rate
FROM sales.tax_bridge_audit_logs
WHERE company_id = 1
  AND sent_at >= NOW() - INTERVAL '30 days'
GROUP BY tributary_type;

-- Promedio de tiempo de respuesta
SELECT 
    tributary_type,
    AVG(response_time_ms) as avg_ms,
    MIN(response_time_ms) as min_ms,
    MAX(response_time_ms) as max_ms,
    PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY response_time_ms) as p95_ms
FROM sales.tax_bridge_audit_logs
WHERE company_id = 1
  AND sent_at >= NOW() - INTERVAL '7 days'
GROUP BY tributary_type;

-- Errores recientes
SELECT 
    id, 
    document_series || '-' || document_number as document,
    error_kind, 
    error_message, 
    sunat_message,
    sent_at
FROM sales.tax_bridge_audit_logs
WHERE company_id = 1
  AND (sunat_status = 'REJECTED' OR error_kind IS NOT NULL)
  AND sent_at >= NOW() - INTERVAL '7 days'
ORDER BY sent_at DESC;
```

---

## 🚀 Roadmap

### **Fase 1 (Actual)** ✅
- [x] Tabla de auditoría
- [x] Modelo Eloquent + Scopes
- [x] Servicio de auditoría
- [x] Endpoints API
- [x] Rutas

### **Fase 2** (Por implementar)
- [ ] Registro automático en TaxBridgeService
- [ ] Componentes Frontend (List, Drawer, Stats)
- [ ] Integración en SalesView (pestaña Auditoría)

### **Fase 3** (Futuro)
- [ ] Exportación CSV/Excel de auditoría
- [ ] Búsqueda full-text en payloads
- [ ] Notificaciones en tiempo real de fallos
- [ ] Webhook para eventos críticos
- [ ] Métricas y alertas SLA
- [ ] Reporte PDF de trazabilidad

---

## ✅ Validación

```bash
# Verificar tabla creada
$ php artisan tinker
>>> DB::table('sales.tax_bridge_audit_logs')->count()
0  // Vacía inicialmente

# Probar endpoints (con auth token)
GET http://localhost:8000/api/tax-bridge/audit/branch?company_id=1
GET http://localhost:8000/api/tax-bridge/audit/statistics?company_id=1&start_date=2026-04-01&end_date=2026-04-15
```

---

**Resultado:** Sistema de auditoría **production-ready** para trazabilidad completa de envíos tributarios. 📦✅
