<?php

namespace App\Infrastructure\Models\Sales;

use Illuminate\Database\Eloquent\Model;

class TaxBridgeAuditLog extends Model
{
    protected $table = 'sales.tax_bridge_audit_logs';
    
    protected $fillable = [
        'company_id',
        'branch_id',
        'document_id',
        'document_kind',
        'document_series',
        'document_number',
        'tributary_type',
        'bridge_mode',
        'endpoint_url',
        'http_method',
        'content_type',
        'request_payload',
        'request_size_bytes',
        'request_sha1_hash',
        'response_body',
        'response_size_bytes',
        'http_status_code',
        'response_time_ms',
        'sunat_status',
        'sunat_code',
        'ticket_number',
        'cdr_code',
        'sunat_message',
        'request_form_data',
        'auth_scheme',
        'debug_notes',
        'error_message',
        'error_kind',
        'attempt_number',
        'is_retry',
        'is_manual_dispatch',
        'initiated_by_user_id',
        'initiated_by_username',
        'sent_at',
        'received_at',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'document_id' => 'integer',
        'request_size_bytes' => 'integer',
        'response_size_bytes' => 'integer',
        'http_status_code' => 'integer',
        'response_time_ms' => 'float',
        'attempt_number' => 'integer',
        'is_retry' => 'boolean',
        'is_manual_dispatch' => 'boolean',
        'initiated_by_user_id' => 'integer',
        'sent_at' => 'datetime',
        'received_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function document()
    {
        return $this->belongsTo(CommercialDocument::class, 'document_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function initiatedByUser()
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    /**
     * Scope: últimos envíos para un documento
     */
    public function scopeForDocument($query, int $documentId)
    {
        return $query->where('document_id', $documentId)->orderByDesc('sent_at');
    }

    /**
     * Scope: últimos envíos para una empresa/rama
     */
    public function scopeForBranch($query, int $companyId, ?int $branchId = null)
    {
        $query->where('company_id', $companyId);
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }
        return $query;
    }

    /**
     * Scope: filtrar por tipo tributario
     */
    public function scopeByTributaryType($query, string $type)
    {
        return $query->where('tributary_type', $type);
    }

    /**
     * Scope: filtrar por estado
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('sunat_status', $status);
    }

    /**
     * Scope: resultados exitosos
     */
    public function scopeAccepted($query)
    {
        return $query->where('sunat_status', 'ACCEPTED');
    }

    /**
     * Scope: resultados rechazados
     */
    public function scopeRejected($query)
    {
        return $query->where('sunat_status', 'REJECTED');
    }

    /**
     * Scope: pendientes de confirmación
     */
    public function scopePending($query)
    {
        return $query->where('sunat_status', 'PENDING_CONFIRMATION');
    }

    /**
     * Scope: solo reintentos
     */
    public function scopeRetries($query)
    {
        return $query->where('is_retry', true);
    }

    /**
     * Scope: filtrar por rango de fechas
     */
    public function scopeBetweenDates($query, $startDate, $endDate)
    {
        return $query->whereBetween('sent_at', [$startDate, $endDate]);
    }

    /**
     * Obtener estadísticas por tipo tributario
     */
    public static function getStatsByTributaryType(int $companyId, ?int $branchId = null, $startDate = null, $endDate = null)
    {
        $query = static::forBranch($companyId, $branchId);

        if ($startDate && $endDate) {
            $query->betweenDates($startDate, $endDate);
        }

        return $query->groupBy('tributary_type')
            ->selectRaw('tributary_type, sunat_status, COUNT(*) as count, AVG(response_time_ms) as avg_response_time_ms')
            ->get();
    }

    /**
     * Obtener últimos intentos fallidos
     */
    public static function getRecentFailures(int $companyId, ?int $branchId = null, int $limit = 20)
    {
        return static::forBranch($companyId, $branchId)
            ->byStatus('REJECTED')
            ->orWhere('error_kind', '!=', null)
            ->orderByDesc('sent_at')
            ->limit($limit)
            ->get();
    }
}
