<?php

namespace App\Services\Sales\TaxBridge;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class TaxBridgeService
{
    private const RECONCILE_WARN_ATTEMPTS = 8;

    public function __construct(
        private TaxBridgePayloadBuilder $payloadBuilder,
        private TaxBridgeAuditService $auditService
    )
    {
    }

    public function supportsDocumentKind(string $documentKind, ?int $documentKindId = null): bool
    {
        if ($documentKindId !== null && $documentKindId > 0) {
            $catalogCode = DB::table('sales.document_kinds')
                ->where('id', $documentKindId)
                ->value('code');

            if (is_string($catalogCode) && trim($catalogCode) !== '') {
                $documentKind = $catalogCode;
            }
        }

        $normalized = strtoupper(trim($documentKind));

        if ($normalized === 'CREDIT_NOTE' || str_starts_with($normalized, 'CREDIT_NOTE_')) {
            return true;
        }

        if ($normalized === 'DEBIT_NOTE' || str_starts_with($normalized, 'DEBIT_NOTE_')) {
            return true;
        }

        return in_array($normalized, ['INVOICE', 'RECEIPT'], true);
    }

    public function dispatchOnIssue(int $companyId, ?int $branchId, int $documentId): void
    {
        $config = $this->resolveConfig($companyId, $branchId);

        if (!$config['enabled']) {
            $this->updateDocumentTaxStatus($companyId, $documentId, [
                'sunat_status' => 'PENDING_MANUAL',
                'sunat_status_label' => 'Pendiente manual',
                'sunat_bridge_note' => 'Puente tributario deshabilitado en AppCfg',
            ]);
            return;
        }

        if (!$config['auto_send_on_issue']) {
            $this->updateDocumentTaxStatus($companyId, $documentId, [
                'sunat_status' => 'PENDING_MANUAL',
                'sunat_status_label' => 'Pendiente manual',
                'sunat_bridge_mode' => $config['bridge_mode'],
                'sunat_bridge_note' => 'Autoenvio deshabilitado',
            ]);
            return;
        }

        if ((bool) ($config['force_async_on_issue'] ?? false)) {
            $this->updateDocumentTaxStatus($companyId, $documentId, [
                'sunat_status' => 'QUEUED',
                'sunat_status_label' => 'En cola de envio',
                'sunat_bridge_mode' => $config['bridge_mode'],
                'sunat_bridge_note' => 'Envio SUNAT programado en segundo plano',
            ]);

            app()->terminating(function () use ($companyId, $documentId, $config): void {
                try {
                    $this->performDispatch($companyId, $documentId, $config, false);
                } catch (\Throwable $e) {
                    Log::error('TaxBridge async dispatch failed', [
                        'company_id' => $companyId,
                        'document_id' => $documentId,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

            return;
        }

        $this->performDispatch($companyId, $documentId, $config, false);
    }

    public function deferDispatch(int $companyId, int $documentId): void
    {
        $this->updateDocumentTaxStatus($companyId, $documentId, [
            'sunat_status'       => 'PENDING_MANUAL',
            'sunat_status_label' => 'Pendiente envío',
            'sunat_bridge_note'  => 'Envío diferido por el usuario',
        ]);
    }

    public function retry(int $companyId, int $documentId): array
    {
        $document = DB::table('sales.commercial_documents')
            ->where('id', $documentId)
            ->where('company_id', $companyId)
            ->first();

        if (!$document) {
            throw new TaxBridgeException('Document not found', 404);
        }

        if (!$this->supportsDocumentKind((string) $document->document_kind, isset($document->document_kind_id) ? (int) $document->document_kind_id : null)) {
            throw new TaxBridgeException('Document type is not tributary (INVOICE/RECEIPT/CREDIT_NOTE/DEBIT_NOTE)', 422);
        }

        if (strtoupper((string) ($document->status ?? '')) !== 'ISSUED') {
            throw new TaxBridgeException('Document must be in ISSUED status to reattempt tax bridge send', 422);
        }

        $metadata = json_decode((string) ($document->metadata ?? '{}'), true);
        $metadata = is_array($metadata) ? $metadata : [];
        $sunatStatus = strtoupper(trim((string) ($metadata['sunat_status'] ?? '')));

        if ($sunatStatus === 'PENDING_CONFIRMATION') {
            $nextAtRaw = (string) ($metadata['sunat_reconcile_next_at'] ?? '');
            if ($nextAtRaw !== '') {
                $isCoolingDown = false;
                try {
                    $nextAt = \Carbon\Carbon::parse($nextAtRaw);
                    $isCoolingDown = $nextAt->greaterThan(now());
                } catch (\Throwable $e) {
                    // Ignore parse failures and allow manual retry.
                }

                if ($isCoolingDown) {
                    throw new TaxBridgeException('Documento en verificacion automatica SUNAT. Espere unos minutos antes de reenviar.', 422);
                }
            }
        }

        $config = $this->resolveConfig($companyId, $document->branch_id !== null ? (int) $document->branch_id : null);
        if (!$config['enabled']) {
            throw new TaxBridgeException('Tax bridge is not enabled in AppCfg', 422);
        }

        return $this->performDispatch($companyId, $documentId, $config, true);
    }

    public function preview(int $companyId, int $documentId): array
    {
        $document = DB::table('sales.commercial_documents')
            ->where('id', $documentId)
            ->where('company_id', $companyId)
            ->first();

        if (!$document) {
            throw new TaxBridgeException('Document not found', 404);
        }

        if (!$this->supportsDocumentKind((string) $document->document_kind, isset($document->document_kind_id) ? (int) $document->document_kind_id : null)) {
            throw new TaxBridgeException('Document type is not tributary (INVOICE/RECEIPT/CREDIT_NOTE/DEBIT_NOTE)', 422);
        }

        $config = $this->resolveConfig($companyId, $document->branch_id !== null ? (int) $document->branch_id : null);
        if (!$config['enabled']) {
            throw new TaxBridgeException('Tax bridge is not enabled in AppCfg', 422);
        }

        if ($config['endpoint_url'] === '') {
            throw new TaxBridgeException('Tax bridge endpoint URL is not configured', 422);
        }

        $payload = $this->payloadBuilder->build($companyId, $documentId, $config);
        if ($payload === null) {
            throw new TaxBridgeException('Failed to build tax bridge payload', 500);
        }

        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($payloadJson)) {
            $payloadJson = '{}';
        }

        $debugPayload = $this->sanitizePayloadForDebug($payload);

        return [
            'bridge_mode' => $config['bridge_mode'],
            'endpoint' => $config['endpoint_url'],
            'method' => 'POST',
            'content_type' => 'application/x-www-form-urlencoded',
            'form_key' => 'datosJSON',
            'payload' => $debugPayload,
            'request_json' => $debugPayload,
            'payload_length' => strlen($payloadJson),
            'payload_sha1' => sha1($payloadJson),
        ];
    }

    public function sendVoidCommunication(int $companyId, int $documentId, ?string $reason = null): array
    {
        $document = DB::table('sales.commercial_documents')
            ->where('id', $documentId)
            ->where('company_id', $companyId)
            ->first();

        if (!$document) {
            throw new TaxBridgeException('Document not found', 404);
        }

        $documentKind = strtoupper((string) ($document->document_kind ?? ''));
        if ($documentKind === 'RECEIPT') {
            throw new TaxBridgeException('Boletas aceptadas se anulan por resumen diario (pendiente de implementacion)', 422);
        }

        if (!in_array($documentKind, ['INVOICE', 'CREDIT_NOTE', 'DEBIT_NOTE'], true)) {
            throw new TaxBridgeException('Solo facturas y notas (credito/debito) usan comunicacion de baja SUNAT directa', 422);
        }

        if (strtoupper((string) ($document->status ?? '')) !== 'ISSUED') {
            throw new TaxBridgeException('Document must be in ISSUED status to request SUNAT void communication', 422);
        }

        $metadata = json_decode((string) ($document->metadata ?? '{}'), true);
        $metadata = is_array($metadata) ? $metadata : [];
        $sunatStatus = strtoupper(trim((string) ($metadata['sunat_status'] ?? '')));

        if ($sunatStatus !== 'ACCEPTED') {
            throw new TaxBridgeException('SUNAT void communication requires accepted tributary document', 422);
        }

        $config = $this->resolveConfig($companyId, $document->branch_id !== null ? (int) $document->branch_id : null);
        if (!$config['enabled']) {
            throw new TaxBridgeException('Tax bridge is not enabled in AppCfg', 422);
        }

        if ($config['endpoint_url'] === '') {
            throw new TaxBridgeException('Tax bridge endpoint URL is not configured', 422);
        }

        $endpoint = $this->resolveBridgeEndpoint($config['endpoint_url'], 'send_anulacion');
        if ($endpoint === '') {
            throw new TaxBridgeException('Tax bridge endpoint URL is not configured for send_anulacion', 422);
        }

        $payload = $this->payloadBuilder->build($companyId, $documentId, $config);
        if ($payload === null) {
            throw new TaxBridgeException('Failed to build tax bridge payload for void communication', 500);
        }

        $voidNumber = $this->nextVoidCommunicationNumber($companyId);
        $payload['anulado'] = [
            'numero' => $voidNumber,
            'motivo' => trim((string) ($reason ?? '')),
        ];

        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($payloadJson)) {
            $payloadJson = '{}';
        }

        $this->updateDocumentTaxStatus($companyId, $documentId, [
            'sunat_void_status' => 'SENDING',
            'sunat_void_label' => 'Comunicando baja a SUNAT',
            'sunat_void_requested_at' => now()->toDateTimeString(),
            'sunat_void_number' => $voidNumber,
            'sunat_void_endpoint' => $endpoint,
            'sunat_void_reason' => trim((string) ($reason ?? '')),
        ]);

        try {
            $requestStartedAt = microtime(true);
            $request = Http::timeout((int) $config['timeout_seconds'])->acceptJson();

            if ($config['auth_scheme'] === 'bearer' && $config['token'] !== '') {
                $request = $request->withToken($config['token']);
            }

            $response = $request->asForm()->post($endpoint, [
                'datosJSON' => $payloadJson,
            ]);
            $responseTimeMs = round((microtime(true) - $requestStartedAt) * 1000, 2);

            $raw = (string) $response->body();
            $decoded = json_decode($raw, true);
            if (is_string($decoded)) {
                $decodedNested = json_decode($decoded, true);
                if (is_array($decodedNested)) {
                    $decoded = $decodedNested;
                }
            }

            $bridgeResCode = $this->extractBridgeResponseCode($decoded);
            $bridgeState = strtoupper(trim($this->extractBridgeResponseState($decoded)));
            $bridgeMessage = $this->extractBridgeResponseMessage($decoded, $raw);
            $bridgeTicket = $this->extractBridgeTicket($decoded);
            $finalBridgeCode = $this->extractBridgeFinalCdrCode($decoded, $bridgeMessage . ' ' . $raw);

            $status = 'SENT';
            $label = 'Comunicacion de baja enviada';

            if (!$response->successful()) {
                $status = 'HTTP_ERROR';
                $label = 'Error HTTP en comunicacion de baja';
            } elseif ($finalBridgeCode !== null) {
                if ($finalBridgeCode === 0 || $finalBridgeCode === 2323 || $finalBridgeCode === 2324 || $finalBridgeCode >= 4000) {
                    $status = 'ACCEPTED';
                    $label = 'Comunicacion de baja aceptada';
                } elseif ($finalBridgeCode >= 2000 && $finalBridgeCode <= 3999) {
                    $status = 'REJECTED';
                    $label = 'Comunicacion de baja rechazada';
                }
            } elseif ($bridgeTicket !== null && ($this->containsBridgeErrorMarkers($bridgeMessage) || $this->containsBridgeErrorMarkers($raw))) {
                $status = 'SENT';
                $label = 'Comunicacion de baja enviada; ticket pendiente';
            } elseif ($bridgeResCode === 1 && $bridgeTicket === null && !$this->containsBridgeErrorMarkers($bridgeMessage)) {
                $status = 'ACCEPTED';
                $label = 'Comunicacion de baja aceptada';
            } elseif ($bridgeResCode === 0 || in_array($bridgeState, ['RECHAZADO', 'REJECTED', 'ERROR'], true)) {
                $status = 'REJECTED';
                $label = 'Comunicacion de baja rechazada';
            } elseif (in_array($bridgeState, ['ACEPTADO', 'ACCEPTED', 'OK'], true) && $bridgeTicket === null) {
                $status = 'ACCEPTED';
                $label = 'Comunicacion de baja aceptada';
            }

            $this->updateDocumentTaxStatus($companyId, $documentId, [
                'sunat_void_status' => $status,
                'sunat_void_label' => $label,
                'sunat_void_http_code' => $response->status(),
                'sunat_void_response' => is_array($decoded) ? $decoded : ['raw' => substr($raw, 0, 1500)],
                'sunat_void_ticket' => is_array($decoded) ? ($decoded['ticket'] ?? null) : null,
            ]);

            if ($status === 'ACCEPTED') {
                DB::table('sales.commercial_documents')
                    ->where('id', $documentId)
                    ->where('company_id', $companyId)
                    ->update([
                        'status' => 'VOID',
                        'updated_at' => now(),
                    ]);

                $this->reverseInventoryForVoidedDocumentIfNeeded($companyId, $documentId);
            }

            $this->auditService->logDispatch(
                $companyId,
                $document->branch_id !== null ? (int) $document->branch_id : null,
                'SUNAT_DIRECT',
                (int) $documentId,
                isset($document->document_kind) ? (string) $document->document_kind : null,
                isset($document->series) ? (string) $document->series : null,
                isset($document->number) ? (string) $document->number : null,
                [
                    'bridge_mode' => $config['bridge_mode'] ?? 'PRODUCTION',
                    'endpoint_url' => $endpoint,
                    'auth_scheme' => $config['auth_scheme'] ?? 'none',
                ],
                $payloadJson,
                substr($raw, 0, 100000),
                (int) $response->status(),
                $responseTimeMs,
                [
                    'code' => $finalBridgeCode !== null ? (string) $finalBridgeCode : ($bridgeResCode !== null ? (string) $bridgeResCode : null),
                    'ticket' => $bridgeTicket,
                    'cdr_code' => $finalBridgeCode !== null ? (string) $finalBridgeCode : null,
                    'message' => $bridgeMessage,
                ],
                [
                    'sunat_status' => $status,
                    'error_kind' => !$response->successful() ? 'HTTP_ERROR' : null,
                    'attempt_number' => 1,
                    'is_retry' => false,
                    'is_manual' => true,
                ]
            );

            return [
                'status' => $status,
                'label' => $label,
                'bridge_http_code' => $response->status(),
                'response' => is_array($decoded) ? $decoded : ['raw' => substr($raw, 0, 1500)],
                'void_number' => $voidNumber,
                'debug' => [
                    'bridge_mode' => $config['bridge_mode'],
                    'endpoint' => $endpoint,
                    'method' => 'POST',
                    'content_type' => 'application/x-www-form-urlencoded',
                    'form_key' => 'datosJSON',
                    'payload' => $this->sanitizePayloadForDebug($payload),
                    'payload_length' => strlen($payloadJson),
                    'payload_sha1' => sha1($payloadJson),
                ],
            ];
        } catch (\Throwable $e) {
            $this->updateDocumentTaxStatus($companyId, $documentId, [
                'sunat_void_status' => 'NETWORK_ERROR',
                'sunat_void_label' => 'Error de red en comunicacion de baja',
                'sunat_void_response' => ['error' => substr($e->getMessage(), 0, 500)],
            ]);

            $this->auditService->logDispatch(
                $companyId,
                $document->branch_id !== null ? (int) $document->branch_id : null,
                'SUNAT_DIRECT',
                (int) $documentId,
                isset($document->document_kind) ? (string) $document->document_kind : null,
                isset($document->series) ? (string) $document->series : null,
                isset($document->number) ? (string) $document->number : null,
                [
                    'bridge_mode' => $config['bridge_mode'] ?? 'PRODUCTION',
                    'endpoint_url' => $endpoint,
                    'auth_scheme' => $config['auth_scheme'] ?? 'none',
                ],
                $payloadJson,
                null,
                null,
                null,
                [
                    'code' => null,
                    'ticket' => null,
                    'cdr_code' => null,
                    'message' => substr($e->getMessage(), 0, 400),
                ],
                [
                    'sunat_status' => 'NETWORK_ERROR',
                    'error_kind' => 'NETWORK_ERROR',
                    'error_message' => $e->getMessage(),
                    'attempt_number' => 1,
                    'is_retry' => false,
                    'is_manual' => true,
                ]
            );

            throw new TaxBridgeException('SUNAT void communication failed: ' . $e->getMessage(), 500);
        }
    }

    public function getLastDispatchDebug(int $companyId, int $documentId): ?array
    {
        $document = DB::table('sales.commercial_documents')
            ->where('id', $documentId)
            ->where('company_id', $companyId)
            ->select('id', 'branch_id', 'document_kind', 'document_kind_id', 'metadata')
            ->first();

        if (!$document) {
            return null;
        }

        $metadata = json_decode((string) ($document->metadata ?? '{}'), true);
        $metadata = is_array($metadata) ? $metadata : [];

        $request = is_array($metadata['sunat_bridge_request'] ?? null)
            ? $metadata['sunat_bridge_request']
            : null;

        if ($request === null && empty($metadata['sunat_bridge_endpoint'])) {
            try {
                if ($this->supportsDocumentKind(
                    (string) ($document->document_kind ?? ''),
                    isset($document->document_kind_id) ? (int) $document->document_kind_id : null
                )) {
                    $preview = $this->preview($companyId, $documentId);

                    return [
                        'bridge_mode' => (string) ($metadata['sunat_bridge_mode'] ?? ($preview['bridge_mode'] ?? '')),
                        'sunat_status' => (string) ($metadata['sunat_status'] ?? ''),
                        'sunat_status_label' => (string) ($metadata['sunat_status_label'] ?? ''),
                        'endpoint' => (string) ($metadata['sunat_bridge_endpoint'] ?? ($preview['endpoint'] ?? '')),
                        'method' => (string) ($metadata['sunat_bridge_method'] ?? ($preview['method'] ?? 'POST')),
                        'content_type' => (string) ($metadata['sunat_bridge_content_type'] ?? ($preview['content_type'] ?? 'application/x-www-form-urlencoded')),
                        'form_key' => (string) ($preview['form_key'] ?? 'datosJSON'),
                        'payload' => $preview['payload'] ?? null,
                        'payload_length' => $preview['payload_length'] ?? null,
                        'payload_sha1' => $preview['payload_sha1'] ?? null,
                        'bridge_http_code' => $metadata['sunat_bridge_http_code'] ?? null,
                        'bridge_response' => $metadata['sunat_bridge_response'] ?? null,
                        'sunat_ticket' => $metadata['sunat_ticket'] ?? null,
                        'bridge_note' => (string) ($metadata['sunat_bridge_note'] ?? 'Preview actual generado: no habia dispatch persistido para este comprobante'),
                        'sunat_error_code' => $this->summarizeBridgeDiagnostic($metadata['sunat_bridge_response'] ?? null)['code'],
                        'sunat_error_message' => $this->summarizeBridgeDiagnostic($metadata['sunat_bridge_response'] ?? null)['message'],
                    ];
                }
            } catch (\Throwable $e) {
                return [
                    'bridge_mode' => (string) ($metadata['sunat_bridge_mode'] ?? ''),
                    'sunat_status' => (string) ($metadata['sunat_status'] ?? ''),
                    'sunat_status_label' => (string) ($metadata['sunat_status_label'] ?? ''),
                    'endpoint' => (string) ($metadata['sunat_bridge_endpoint'] ?? ''),
                    'method' => (string) ($metadata['sunat_bridge_method'] ?? 'POST'),
                    'content_type' => (string) ($metadata['sunat_bridge_content_type'] ?? 'application/x-www-form-urlencoded'),
                    'form_key' => 'datosJSON',
                    'payload' => null,
                    'payload_length' => null,
                    'payload_sha1' => null,
                    'bridge_http_code' => $metadata['sunat_bridge_http_code'] ?? null,
                    'bridge_response' => $metadata['sunat_bridge_response'] ?? null,
                    'sunat_ticket' => $metadata['sunat_ticket'] ?? null,
                    'bridge_note' => 'No existe dispatch persistido y no se pudo reconstruir el preview actual: ' . substr($e->getMessage(), 0, 220),
                    'sunat_error_code' => $this->summarizeBridgeDiagnostic($metadata['sunat_bridge_response'] ?? null)['code'],
                    'sunat_error_message' => $this->summarizeBridgeDiagnostic($metadata['sunat_bridge_response'] ?? null)['message'],
                ];
            }

            return [
                'bridge_mode' => (string) ($metadata['sunat_bridge_mode'] ?? ''),
                'sunat_status' => (string) ($metadata['sunat_status'] ?? ''),
                'sunat_status_label' => (string) ($metadata['sunat_status_label'] ?? ''),
                'endpoint' => (string) ($metadata['sunat_bridge_endpoint'] ?? ''),
                'method' => (string) ($metadata['sunat_bridge_method'] ?? 'POST'),
                'content_type' => (string) ($metadata['sunat_bridge_content_type'] ?? 'application/x-www-form-urlencoded'),
                'form_key' => 'datosJSON',
                'payload' => null,
                'payload_length' => null,
                'payload_sha1' => null,
                'bridge_http_code' => $metadata['sunat_bridge_http_code'] ?? null,
                'bridge_response' => $metadata['sunat_bridge_response'] ?? null,
                'sunat_ticket' => $metadata['sunat_ticket'] ?? null,
                'bridge_note' => (string) ($metadata['sunat_bridge_note'] ?? 'No existe dispatch persistido para este comprobante'),
                'sunat_error_code' => $this->summarizeBridgeDiagnostic($metadata['sunat_bridge_response'] ?? null)['code'],
                'sunat_error_message' => $this->summarizeBridgeDiagnostic($metadata['sunat_bridge_response'] ?? null)['message'],
            ];
        }

        return [
            'bridge_mode' => (string) ($metadata['sunat_bridge_mode'] ?? ''),
            'sunat_status' => (string) ($metadata['sunat_status'] ?? ''),
            'sunat_status_label' => (string) ($metadata['sunat_status_label'] ?? ''),
            'endpoint' => (string) ($metadata['sunat_bridge_endpoint'] ?? ''),
            'method' => (string) ($metadata['sunat_bridge_method'] ?? 'POST'),
            'content_type' => (string) ($metadata['sunat_bridge_content_type'] ?? 'application/x-www-form-urlencoded'),
            'form_key' => (string) (($request['form_key'] ?? 'datosJSON')),
            'payload' => $request['payload_json'] ?? null,
            'payload_length' => $request['payload_length'] ?? null,
            'payload_sha1' => $request['payload_sha1'] ?? null,
            'bridge_http_code' => $metadata['sunat_bridge_http_code'] ?? null,
            'bridge_response' => $metadata['sunat_bridge_response'] ?? null,
            'sunat_ticket' => $metadata['sunat_ticket'] ?? null,
            'bridge_note' => (string) ($metadata['sunat_bridge_note'] ?? ''),
            'sunat_error_code' => $this->summarizeBridgeDiagnostic($metadata['sunat_bridge_response'] ?? null)['code'],
            'sunat_error_message' => $this->summarizeBridgeDiagnostic($metadata['sunat_bridge_response'] ?? null)['message'],
        ];
    }

    public function summarizeBridgeDiagnostic($response): array
    {
        $decoded = $response;
        $raw = '';

        if (is_string($response)) {
            $raw = trim($response);
            $parsed = json_decode($raw, true);
            if (is_array($parsed)) {
                $decoded = $parsed;
            }
        } elseif (is_array($response)) {
            $raw = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        } else {
            $decoded = null;
        }

        $message = $this->extractBridgeResponseMessage($decoded, $raw);
        $message = $this->compactBridgeText($message !== '' ? $message : $raw);
        $code = $this->extractBridgeFinalCdrCode($decoded, trim($message . ' ' . $raw));

        if ($message === '' && is_array($decoded)) {
            foreach (['error', 'detail', 'details'] as $key) {
                if (array_key_exists($key, $decoded) && is_scalar($decoded[$key])) {
                    $message = $this->compactBridgeText((string) $decoded[$key]);
                    break;
                }
            }
        }

        return [
            'code' => $code !== null ? (string) $code : null,
            'message' => $message !== '' ? $message : null,
            'ticket' => $this->extractBridgeTicket($decoded),
        ];
    }

    public function registerCertificate(int $companyId, ?int $branchId, array $payload, ?string $certTempPath, ?string $certOriginalName): array
    {
        $config = $this->resolveConfig($companyId, $branchId);
        $sendXmlEndpoint = trim((string) ($config['endpoint_url'] ?? ''));
        $endpoint = $this->resolveRegisterCertEndpoint($sendXmlEndpoint);

        if ($endpoint === '') {
            throw new TaxBridgeException('Tax bridge endpoint URL is not configured', 422);
        }

        $request = Http::timeout((int) $config['timeout_seconds'])->acceptJson();

        if ($config['auth_scheme'] === 'bearer' && $config['token'] !== '') {
            $request = $request->withToken($config['token']);
        }

        if ($certTempPath !== null && $certTempPath !== '' && is_readable($certTempPath)) {
            $request = $request->attach(
                'certificado',
                file_get_contents($certTempPath),
                $certOriginalName !== null && $certOriginalName !== '' ? $certOriginalName : 'certificado.p12'
            );
        }

        $response = $request->post($endpoint, $payload);
        $rawBody = trim((string) $response->body());
        $decoded = json_decode($rawBody, true);

        return [
            'endpoint' => $endpoint,
            'http_code' => $response->status(),
            'payload' => $this->sanitizeCertPayloadForDebug($payload),
            'raw_response' => $rawBody,
            'json_response' => is_array($decoded) ? $decoded : null,
            'legacy_code' => $this->resolveLegacyResponseCode($rawBody, $decoded),
        ];
    }

    private function performDispatch(int $companyId, int $documentId, array $config, bool $isRetry): array
    {
        $document = DB::table('sales.commercial_documents')
            ->where('id', $documentId)
            ->where('company_id', $companyId)
            ->select('id', 'issue_at', 'branch_id', 'document_kind', 'series', 'number')
            ->first();

        if (!$document) {
            if ($isRetry) {
                throw new TaxBridgeException('Document not found', 404);
            }

            return [
                'status' => 'ERROR',
                'label' => 'Documento no encontrado',
            ];
        }

        if ($this->isOutsideSunatIssueWindow($document->issue_at ?? null)) {
            $this->updateDocumentTaxStatus($companyId, $documentId, [
                'sunat_status' => 'EXPIRED_WINDOW',
                'sunat_status_label' => 'Fuera de plazo SUNAT (3 dias)',
                'sunat_reconcile_auto_enabled' => false,
                'sunat_needs_manual_confirmation' => true,
                'sunat_bridge_note' => 'No se envia automaticamente: la fecha de emision supera la ventana de 3 dias de SUNAT',
                'sunat_reconcile_next_at' => null,
            ]);

            if ($isRetry) {
                throw new TaxBridgeException('Documento fuera de plazo SUNAT: solo se permite envio dentro de 3 dias desde la emision', 422);
            }

            return [
                'status' => 'EXPIRED_WINDOW',
                'label' => 'Fuera de plazo SUNAT (3 dias)',
            ];
        }

        if ($config['endpoint_url'] === '') {
            if ($isRetry) {
                throw new TaxBridgeException('Tax bridge endpoint URL is not configured', 422);
            }

            $this->updateDocumentTaxStatus($companyId, $documentId, [
                'sunat_status' => 'CONFIG_INCOMPLETE',
                'sunat_status_label' => 'Config incompleta',
                'sunat_bridge_mode' => $config['bridge_mode'],
                'sunat_bridge_note' => 'URL de puente no configurada',
            ]);

            return [
                'status' => 'CONFIG_INCOMPLETE',
                'label' => 'Config incompleta',
            ];
        }

        $payload = $this->payloadBuilder->build($companyId, $documentId, $config);
        if ($payload === null) {
            if ($isRetry) {
                throw new TaxBridgeException('Failed to build tax bridge payload', 500);
            }

            $this->updateDocumentTaxStatus($companyId, $documentId, [
                'sunat_status' => 'ERROR',
                'sunat_status_label' => 'Error payload',
                'sunat_bridge_mode' => $config['bridge_mode'],
                'sunat_bridge_note' => 'No se pudo construir payload tributario',
            ]);

            return [
                'status' => 'ERROR',
                'label' => 'Error payload',
            ];
        }

        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($payloadJson)) {
            $payloadJson = '{}';
        }
        $debugPayload = $this->sanitizePayloadForDebug($payload);

        $this->updateDocumentTaxStatus($companyId, $documentId, [
            'sunat_status' => 'SENDING',
            'sunat_status_label' => $isRetry ? 'Enviando (reintento)' : 'Enviando',
            'sunat_bridge_mode' => $config['bridge_mode'],
            'sunat_bridge_endpoint' => $config['endpoint_url'],
            'sunat_bridge_method' => 'POST',
            'sunat_bridge_content_type' => 'application/x-www-form-urlencoded',
            'sunat_bridge_request' => [
                'form_key' => 'datosJSON',
                'payload_length' => strlen($payloadJson),
                'payload_sha1' => sha1($payloadJson),
                'payload_json' => $debugPayload,
                'payload_preview' => substr($payloadJson, 0, 5000),
            ],
            'sunat_retry_at' => $isRetry ? now()->toDateTimeString() : null,
        ]);

        try {
            $requestStartedAt = microtime(true);
            $request = Http::timeout((int) $config['timeout_seconds'])->acceptJson();

            if ($config['auth_scheme'] === 'bearer' && $config['token'] !== '') {
                $request = $request->withToken($config['token']);
            }

            $response = $request->asForm()->post($config['endpoint_url'], [
                'datosJSON' => $payloadJson,
            ]);

            $raw = (string) $response->body();
            $decoded = json_decode($raw, true);
            if (is_string($decoded)) {
                $decodedNested = json_decode($decoded, true);
                if (is_array($decodedNested)) {
                    $decoded = $decodedNested;
                }
            }

            $scalarBridgeCode = null;
            if (is_int($decoded) || (is_string($decoded) && preg_match('/^(0|1|3)$/', trim((string) $decoded)) === 1)) {
                $scalarBridgeCode = (int) $decoded;
            } elseif (preg_match('/^(0|1|3)$/', trim($raw)) === 1) {
                $scalarBridgeCode = (int) trim($raw);
            }

            $bridgeResCode = $this->extractBridgeResponseCode($decoded);
            $bridgeState = strtoupper(trim($this->extractBridgeResponseState($decoded)));
            $bridgeMessage = $this->extractBridgeResponseMessage($decoded, $raw);
            $bridgeTicket = $this->extractBridgeTicket($decoded);
            $bridgeLink = $this->extractBridgeConstancyLink($decoded);
            $bridgeSignature = $this->extractBridgeElectronicSignature($decoded);
            $finalBridgeCode = $this->extractBridgeFinalCdrCode($decoded, $bridgeMessage . ' ' . $raw);
            $status = 'SENT';
            $label = 'Enviado';

            if (!$response->successful()) {
                $status = 'PENDING_CONFIRMATION';
                $label = 'Pendiente confirmacion SUNAT';
            } elseif ($finalBridgeCode !== null) {
                if ($finalBridgeCode === 0 || $finalBridgeCode >= 4000) {
                    $status = 'ACCEPTED';
                    $label = 'Aceptado';
                } elseif ($finalBridgeCode >= 2000 && $finalBridgeCode <= 3999) {
                    $status = 'REJECTED';
                    $label = 'Rechazado';
                }
            } elseif ($bridgeTicket !== null && ($this->containsBridgeErrorMarkers($bridgeMessage) || $this->containsBridgeErrorMarkers($raw))) {
                $status = 'PENDING_CONFIRMATION';
                $label = 'Pendiente confirmacion SUNAT';
            } elseif ($scalarBridgeCode === 1 && $bridgeTicket === null) {
                $status = 'ACCEPTED';
                $label = 'Aceptado';
            } elseif ($scalarBridgeCode === 0) {
                $status = 'REJECTED';
                $label = 'Rechazado';
            } elseif ($bridgeResCode === 1 && $bridgeTicket === null && !$this->containsBridgeErrorMarkers($bridgeMessage)) {
                $status = 'ACCEPTED';
                $label = 'Aceptado';
            } elseif ($bridgeResCode === 0 || in_array($bridgeState, ['RECHAZADO', 'REJECTED', 'ERROR'], true)) {
                $status = 'REJECTED';
                $label = 'Rechazado';
            } elseif (in_array($bridgeState, ['ACEPTADO', 'ACCEPTED', 'OK'], true) && $bridgeTicket === null) {
                $status = 'ACCEPTED';
                $label = 'Aceptado';
            }

            $attemptMeta = $this->buildReconcileAttemptMetadata($companyId, $documentId, $status === 'PENDING_CONFIRMATION', $isRetry, !$response->successful() ? 'HTTP_ERROR' : null);
            $responseTimeMs = round((microtime(true) - $requestStartedAt) * 1000, 2);

            $this->updateDocumentTaxStatus($companyId, $documentId, [
                'sunat_status' => $status,
                'sunat_status_label' => $label,
                'sunat_bridge_http_code' => $response->status(),
                'sunat_bridge_response' => is_array($decoded) ? $decoded : ['raw' => substr($raw, 0, 1500)],
                'sunat_ticket' => is_array($decoded) ? ($decoded['ticket'] ?? null) : null,
                'sunat_constancy_link' => $bridgeLink,
                'sunat_electronic_signature' => $bridgeSignature,
                'sunat_reconcile_attempts' => $attemptMeta['attempts'],
                'sunat_reconcile_next_at' => $attemptMeta['next_at'],
                'sunat_reconcile_auto_enabled' => $attemptMeta['auto_enabled'],
                'sunat_reconcile_last_error_kind' => $attemptMeta['last_error_kind'],
                'sunat_reconcile_last_error_at' => $attemptMeta['last_error_at'],
                'sunat_needs_manual_confirmation' => $attemptMeta['needs_manual_confirmation'],
                'sunat_bridge_note' => $attemptMeta['note'],
            ]);

            if ($status === 'ACCEPTED') {
                $this->settleInventoryForAcceptedDocumentIfNeeded($companyId, $documentId);
            }

            $this->auditService->logDispatch(
                $companyId,
                $document->branch_id !== null ? (int) $document->branch_id : null,
                'SUNAT_DIRECT',
                (int) $documentId,
                isset($document->document_kind) ? (string) $document->document_kind : null,
                isset($document->series) ? (string) $document->series : null,
                isset($document->number) ? (string) $document->number : null,
                $config,
                $payloadJson,
                substr($raw, 0, 100000),
                (int) $response->status(),
                $responseTimeMs,
                [
                    'code' => $finalBridgeCode !== null ? (string) $finalBridgeCode : ($bridgeResCode !== null ? (string) $bridgeResCode : null),
                    'ticket' => $bridgeTicket,
                    'cdr_code' => $finalBridgeCode !== null ? (string) $finalBridgeCode : null,
                    'message' => $bridgeMessage,
                ],
                [
                    'sunat_status' => $status,
                    'error_kind' => !$response->successful() ? 'HTTP_ERROR' : null,
                    'attempt_number' => (int) ($attemptMeta['attempts'] ?? 1),
                    'is_retry' => $isRetry,
                    'is_manual' => $isRetry,
                ]
            );

            return [
                'status' => $status,
                'label' => $label,
                'bridge_http_code' => $response->status(),
                'response' => is_array($decoded) ? $decoded : ['raw' => substr($raw, 0, 1500)],
                'debug' => [
                    'bridge_mode' => $config['bridge_mode'],
                    'endpoint' => $config['endpoint_url'],
                    'method' => 'POST',
                    'content_type' => 'application/x-www-form-urlencoded',
                    'form_key' => 'datosJSON',
                    'payload' => $debugPayload,
                ],
            ];
        } catch (\Throwable $e) {
            Log::warning($isRetry ? 'Tax bridge retry dispatch failed' : 'Tax bridge dispatch failed', [
                'company_id' => $companyId,
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);

            $attemptMeta = $this->buildReconcileAttemptMetadata($companyId, $documentId, true, $isRetry, 'NETWORK_ERROR');

            $this->updateDocumentTaxStatus($companyId, $documentId, [
                'sunat_status' => 'PENDING_CONFIRMATION',
                'sunat_status_label' => 'Pendiente confirmacion SUNAT',
                'sunat_reconcile_attempts' => $attemptMeta['attempts'],
                'sunat_reconcile_next_at' => $attemptMeta['next_at'],
                'sunat_reconcile_auto_enabled' => $attemptMeta['auto_enabled'],
                'sunat_reconcile_last_error_kind' => $attemptMeta['last_error_kind'],
                'sunat_reconcile_last_error_at' => $attemptMeta['last_error_at'],
                'sunat_needs_manual_confirmation' => $attemptMeta['needs_manual_confirmation'],
                'sunat_bridge_note' => $attemptMeta['note'] . ' | ' . substr($e->getMessage(), 0, 350),
            ]);

            $this->auditService->logDispatch(
                $companyId,
                $document->branch_id !== null ? (int) $document->branch_id : null,
                'SUNAT_DIRECT',
                (int) $documentId,
                isset($document->document_kind) ? (string) $document->document_kind : null,
                isset($document->series) ? (string) $document->series : null,
                isset($document->number) ? (string) $document->number : null,
                $config,
                $payloadJson,
                null,
                null,
                null,
                [
                    'code' => null,
                    'ticket' => null,
                    'cdr_code' => null,
                    'message' => substr($e->getMessage(), 0, 400),
                ],
                [
                    'sunat_status' => 'PENDING_CONFIRMATION',
                    'error_kind' => 'NETWORK_ERROR',
                    'error_message' => $e->getMessage(),
                    'attempt_number' => (int) ($attemptMeta['attempts'] ?? 1),
                    'is_retry' => $isRetry,
                    'is_manual' => $isRetry,
                ]
            );

            if ($isRetry) {
                throw new TaxBridgeException('Tax bridge retry failed: ' . $e->getMessage(), 500);
            }

            return [
                'status' => 'PENDING_CONFIRMATION',
                'label' => 'Pendiente confirmacion SUNAT',
            ];
        }
    }

    public function reconcilePendingDocuments(int $limit = 30): array
    {
        $limit = max(1, min(200, $limit));
        $processed = 0;
        $accepted = 0;
        $rejected = 0;
        $pending = 0;
        $failed = 0;

        // Exclude companies that have explicitly disabled automatic reconcile.
        $disabledCompanyIds = DB::table('appcfg.company_feature_toggles')
            ->where('feature_code', 'SALES_TAX_BRIDGE')
            ->whereRaw("LOWER(COALESCE(config->>'auto_reconcile_enabled', 'true')) = 'false'")
            ->pluck('company_id')
            ->toArray();

        $query = DB::table('sales.commercial_documents')
            ->where('status', 'ISSUED')
            ->whereRaw("UPPER(COALESCE(metadata->>'sunat_status','')) IN ('PENDING_CONFIRMATION', 'HTTP_ERROR', 'NETWORK_ERROR')")
            ->where(function ($q) {
                $q->whereRaw("(metadata->>'sunat_reconcile_auto_enabled') IS NULL")
                  ->orWhereRaw("LOWER(COALESCE(metadata->>'sunat_reconcile_auto_enabled','true')) = 'true'");
            })
            ->where(function ($q) {
                $q->whereRaw("(metadata->>'sunat_reconcile_next_at') IS NULL")
                  ->orWhereRaw("(metadata->>'sunat_reconcile_next_at')::timestamp <= NOW()");
            });

                $this->applyTributaryDocumentKindFilter($query, 'document_kind');

        if (!empty($disabledCompanyIds)) {
            $query->whereNotIn('company_id', $disabledCompanyIds);
        }

        $rows = $query->orderBy('updated_at')
            ->limit($limit)
            ->get(['id', 'company_id']);

        foreach ($rows as $row) {
            $processed++;
            try {
                $res = $this->retry((int) $row->company_id, (int) $row->id);
                $status = strtoupper((string) ($res['status'] ?? ''));

                if ($status === 'ACCEPTED') {
                    $accepted++;
                } elseif ($status === 'REJECTED') {
                    $rejected++;
                } elseif ($status === 'PENDING_CONFIRMATION') {
                    $pending++;
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('SUNAT reconcile pending document failed', [
                    'company_id' => (int) $row->company_id,
                    'document_id' => (int) $row->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'processed' => $processed,
            'accepted' => $accepted,
            'rejected' => $rejected,
            'pending' => $pending,
            'failed' => $failed,
        ];
    }

    public function getReconcileStats(int $companyId): array
    {
        $config = $this->resolveConfig($companyId, null);

        $pendingQuery = DB::table('sales.commercial_documents')
            ->where('company_id', $companyId)
            ->where('status', 'ISSUED')
            ->whereRaw("UPPER(COALESCE(metadata->>'sunat_status','')) IN ('PENDING_CONFIRMATION', 'HTTP_ERROR', 'NETWORK_ERROR')");
        $this->applyTributaryDocumentKindFilter($pendingQuery, 'document_kind');
        $pendingCount = $pendingQuery->count();

        $unsentQuery = DB::table('sales.commercial_documents')
            ->where('company_id', $companyId)
            ->where('status', 'ISSUED')
            ->whereRaw("COALESCE(TRIM(metadata->>'sunat_status'),'') = ''");
        $this->applyTributaryDocumentKindFilter($unsentQuery, 'document_kind');
        $unsentCount = $unsentQuery->count();

        $nextAt = DB::table('sales.commercial_documents')
            ->where('company_id', $companyId)
            ->where('status', 'ISSUED')
            ->whereRaw("UPPER(COALESCE(metadata->>'sunat_status','')) IN ('PENDING_CONFIRMATION', 'HTTP_ERROR', 'NETWORK_ERROR')")
            ->whereRaw("(metadata->>'sunat_reconcile_next_at') IS NOT NULL")
            ->whereRaw("LOWER(COALESCE(metadata->>'sunat_reconcile_auto_enabled','true')) = 'true'");
        $this->applyTributaryDocumentKindFilter($nextAt, 'document_kind');
        $nextAt = $nextAt->min(DB::raw("(metadata->>'sunat_reconcile_next_at')::timestamp"));

        return [
            'auto_reconcile_enabled' => $config['auto_reconcile_enabled'],
            'reconcile_batch_size' => $config['reconcile_batch_size'],
            'pending_reconcile_count' => (int) $pendingCount,
            'unsent_count' => (int) $unsentCount,
            'next_reconcile_at' => $nextAt,
        ];
    }

    private function applyTributaryDocumentKindFilter($query, string $column): void
    {
        $query->where(function ($nested) use ($column) {
            $nested->whereRaw("UPPER($column) = 'INVOICE'")
                ->orWhereRaw("UPPER($column) = 'RECEIPT'")
                ->orWhereRaw("UPPER($column) = 'CREDIT_NOTE'")
                ->orWhereRaw("UPPER($column) = 'DEBIT_NOTE'")
                ->orWhereRaw("UPPER($column) LIKE 'CREDIT_NOTE_%'")
                ->orWhereRaw("UPPER($column) LIKE 'DEBIT_NOTE_%'");
        });
    }

    private function buildReconcileAttemptMetadata(int $companyId, int $documentId, bool $pendingConfirmation, bool $isRetry, ?string $errorKind): array
    {
        $meta = DB::table('sales.commercial_documents')
            ->where('id', $documentId)
            ->where('company_id', $companyId)
            ->value('metadata');

        $decoded = json_decode((string) ($meta ?? '{}'), true);
        $decoded = is_array($decoded) ? $decoded : [];
        $currentAttempts = (int) ($decoded['sunat_reconcile_attempts'] ?? 0);

        if ($pendingConfirmation) {
            $attempts = $isRetry ? $currentAttempts + 1 : 1;
            $attempts = max(1, $attempts);

            // Progressive backoff with ceiling to keep retrying automatically
            // without flooding SUNAT endpoints.
            $minutes = min(120, (int) pow(2, min(6, $attempts - 1)));
            $nextAt = now()->addMinutes($minutes)->toDateTimeString();
            $needsManual = $attempts >= self::RECONCILE_WARN_ATTEMPTS;

            return [
                'attempts' => $attempts,
                'next_at' => $nextAt,
                'auto_enabled' => true,
                'last_error_kind' => $errorKind,
                'last_error_at' => now()->toDateTimeString(),
                'needs_manual_confirmation' => $needsManual,
                'note' => $needsManual
                    ? 'Pendiente confirmacion SUNAT. Reintentos automaticos continuan en segundo plano.'
                    : 'Pendiente confirmacion SUNAT. Reintento automatico programado.',
            ];
        }

        return [
            'attempts' => 0,
            'next_at' => null,
            'auto_enabled' => true,
            'last_error_kind' => null,
            'last_error_at' => null,
            'needs_manual_confirmation' => false,
            'note' => null,
        ];
    }

    private function extractBridgeResponseCode($decoded): ?int
    {
        if (!is_array($decoded)) {
            return null;
        }

        $directKeys = ['res', 'resultado', 'status_code', 'code'];
        foreach ($directKeys as $key) {
            if (array_key_exists($key, $decoded)) {
                $value = (int) $decoded[$key];
                if (in_array($value, [0, 1, 3], true)) {
                    return $value;
                }
            }
        }

        foreach ($decoded as $value) {
            if (is_array($value)) {
                $nested = $this->extractBridgeResponseCode($value);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    private function extractBridgeResponseState($decoded): string
    {
        if (!is_array($decoded)) {
            return '';
        }

        $stateKeys = ['estado', 'status', 'estado_sunat', 'sunat_status'];
        foreach ($stateKeys as $key) {
            if (array_key_exists($key, $decoded) && is_scalar($decoded[$key])) {
                return (string) $decoded[$key];
            }
        }

        foreach ($decoded as $value) {
            if (is_array($value)) {
                $nested = $this->extractBridgeResponseState($value);
                if ($nested !== '') {
                    return $nested;
                }
            }
        }

        return '';
    }

    private function extractBridgeResponseMessage($decoded, string $raw): string
    {
        if (is_array($decoded)) {
            foreach (['msg', 'message', 'descripcion', 'description', 'desRespuesta', 'cdr_desc', 'value', 'raw'] as $key) {
                if (array_key_exists($key, $decoded) && is_scalar($decoded[$key])) {
                    return trim((string) $decoded[$key]);
                }
            }
        }

        return trim($raw);
    }

    private function extractBridgeTicket($decoded): ?string
    {
        if (is_array($decoded) && array_key_exists('ticket', $decoded) && is_scalar($decoded['ticket'])) {
            $ticket = trim((string) $decoded['ticket']);
            return $ticket !== '' ? $ticket : null;
        }

        $text = $this->extractBridgeResponseMessage($decoded, '');
        if (preg_match('/TICKET\s*:\s*([0-9]{8,})/i', $text, $matches) === 1) {
            return trim((string) $matches[1]);
        }

        return null;
    }

    private function extractBridgeConstancyLink($decoded): ?string
    {
        $value = $this->extractBridgeStringByKeys($decoded, [
            'link',
            'enlace',
            'url',
            'sunat_link',
            'constancia_link',
            'consulta_url',
            'url_consulta',
            'cdr_link',
            'cdr_url',
        ]);

        if ($value === null) {
            return null;
        }

        $normalized = trim($value);
        if ($normalized === '' || !preg_match('/^https?:\/\//i', $normalized)) {
            return null;
        }

        return $normalized;
    }

    private function extractBridgeElectronicSignature($decoded): ?string
    {
        $value = $this->extractBridgeStringByKeys($decoded, [
            'firma',
            'firma_electronica',
            'firmaDigital',
            'signature',
            'digital_signature',
            'hash_cpe',
            'codigo_hash',
            'digest_value',
            'digestValue',
        ]);

        if ($value === null) {
            return null;
        }

        $normalized = trim($value);
        return $normalized !== '' ? $normalized : null;
    }

    private function extractBridgeStringByKeys($decoded, array $keys): ?string
    {
        if (!is_array($decoded)) {
            return null;
        }

        foreach ($keys as $key) {
            if (array_key_exists($key, $decoded) && is_scalar($decoded[$key])) {
                $value = trim((string) $decoded[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        foreach ($decoded as $value) {
            if (is_array($value)) {
                $nested = $this->extractBridgeStringByKeys($value, $keys);
                if ($nested !== null && trim($nested) !== '') {
                    return trim($nested);
                }
            }
        }

        return null;
    }

    private function extractBridgeFinalCdrCode($decoded, string $message): ?int
    {
        if (is_array($decoded)) {
            foreach (['codRespuesta', 'cdr_code'] as $key) {
                if (array_key_exists($key, $decoded) && is_scalar($decoded[$key]) && is_numeric((string) $decoded[$key])) {
                    return (int) $decoded[$key];
                }
            }
        }

        if (preg_match('/\[\s*CODE\s*\]\s*=>\s*(\d{4})/i', $message, $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/(?:ERROR|COD(?:IGO)?|RESPUESTA)\D{0,30}(\d{4})\b/i', $message, $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/:\s*(\d{4})\b(?!.*:\s*\d{4}\b)/', $message, $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/^\s*(\d{1,4})\b/', $message, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function containsBridgeErrorMarkers(string $text): bool
    {
        $value = strtoupper(trim($text));
        if ($value === '') {
            return false;
        }

        return preg_match('/\[CODE\]\s*=>|SERVIDOR SUNAT NO RESPONDE|ERROR SOAP|SOAPFAULT|FAULTCODE|EXCEPTION|CDR NO ENCONTRADO|NO HA SIDO COMUNICADO|ERROR EN LA LINEA|XML NO CONTIENE|TASA DEL TRIBUTO FALTANTE|TRIBUTO FALTANTE/', $value) === 1;
    }

    private function compactBridgeText(string $text): string
    {
        $value = preg_replace('/<br\s*\/?>/i', ' | ', $text) ?? $text;
        $value = strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value, " \t\n\r\0\x0B|");

        return $value;
    }

    public function downloadDocument(int $companyId, int $documentId, string $downloadMethod): array
    {
        $docTypeMap = [
            'INVOICE'     => '01',
            'RECEIPT'     => '03',
            'CREDIT_NOTE' => '07',
            'DEBIT_NOTE'  => '08',
        ];

        $document = DB::table('sales.commercial_documents as d')
            ->join('core.companies as co', 'co.id', '=', 'd.company_id')
            ->where('d.id', $documentId)
            ->where('d.company_id', $companyId)
            ->select([
                'd.series',
                'd.number',
                'd.document_kind',
                'd.branch_id',
                'co.tax_id as company_ruc',
            ])
            ->first();

        if (!$document) {
            throw new TaxBridgeException('Documento no encontrado', 404);
        }

        $docTypeCode = $docTypeMap[strtoupper((string) $document->document_kind)] ?? null;
        if ($docTypeCode === null) {
            throw new TaxBridgeException('Tipo de documento no soportado para descarga SUNAT', 422);
        }

        $ruc     = (string) ($document->company_ruc ?? '');
        $series  = (string) ($document->series ?? '');
        $number  = (string) ($document->number ?? '');
        $branchId = $document->branch_id !== null ? (int) $document->branch_id : null;

        $config = $this->resolveConfig($companyId, $branchId);
        $rawBase = $config['raw_base_url'];

        if ($rawBase === '') {
            throw new TaxBridgeException('URL del puente tributario no configurada', 422);
        }

        $endpoint = $this->resolveBridgeEndpoint($rawBase, $downloadMethod);
        if ($endpoint === '') {
            throw new TaxBridgeException('No se pudo resolver el endpoint de descarga', 422);
        }

        $fullUrl = rtrim($endpoint, '/')
            . '/' . rawurlencode($ruc)
            . '/' . rawurlencode($docTypeCode)
            . '/' . rawurlencode($series)
            . '/' . rawurlencode($number);

        $request = Http::timeout(max(30, (int) ($config['timeout_seconds'] ?? 30)));

        if ($config['auth_scheme'] === 'bearer' && $config['token'] !== '') {
            $request = $request->withToken($config['token']);
        } elseif ($config['auth_scheme'] === 'basic' && $config['sol_user'] !== '') {
            $request = $request->withBasicAuth($config['sol_user'], $config['sol_pass']);
        }

        $response = $request->get($fullUrl);

        $isXml    = strtolower($downloadMethod) === 'dowload_xml';
        $ext      = $isXml ? 'xml' : 'zip';
        $contentType = $isXml ? 'application/xml' : 'application/zip';

        $bridgeContentType = $response->header('Content-Type');
        if ($bridgeContentType && $bridgeContentType !== '') {
            $contentType = strtok($bridgeContentType, ';');
        }

        $kindShort = strtolower((string) $document->document_kind);
        $filename  = $kindShort . '_' . $series . '_' . $number . '.' . $ext;

        if (!$response->successful()) {
            throw new TaxBridgeException(
                'El puente devolvio HTTP ' . $response->status() . ' al descargar ' . strtoupper($ext),
                502
            );
        }

        $body = (string) $response->body();
        $normalizedBodyStart = strtolower(substr(ltrim($body), 0, 120));
        $looksLikeHtml = str_starts_with($normalizedBodyStart, '<!doctype')
            || str_starts_with($normalizedBodyStart, '<html')
            || str_contains($normalizedBodyStart, '<body');
        $zipSignature = substr($body, 0, 2);

        if ($isXml && $looksLikeHtml) {
            throw new TaxBridgeException('El puente devolvio HTML en lugar de XML', 502);
        }

        if (!$isXml && $zipSignature !== 'PK') {
            throw new TaxBridgeException('El puente no devolvio un ZIP valido para CDR', 502);
        }

        return [
            'body'         => $body,
            'http_status'  => $response->status(),
            'content_type' => $contentType,
            'filename'     => $filename,
            'endpoint'     => $fullUrl,
            'bridge_content_type' => (string) ($response->header('Content-Type') ?? ''),
            'ruc'          => $ruc,
            'doc_type_code'=> $docTypeCode,
            'series'       => $series,
            'number'       => $number,
        ];
    }

    /**
     * Public proxy for DailySummaryService and other internal callers.
     */
    public function resolvePublicConfig(int $companyId, ?int $branchId): array
    {
        return $this->resolveConfig($companyId, $branchId);
    }

    private function resolveConfig(int $companyId, ?int $branchId): array
    {
        $featureCode = 'SALES_TAX_BRIDGE';
        $enabled = $this->isEnabledForContext($companyId, $branchId, $featureCode, false);

        $branchConfig = null;
        if ($branchId !== null) {
            $branchRow = DB::table('appcfg.branch_feature_toggles')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('feature_code', $featureCode)
                ->select('config')
                ->first();

            if ($branchRow && $branchRow->config) {
                $branchConfig = json_decode((string) $branchRow->config, true);
            }
        }

        $companyConfig = null;
        $companyRow = DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->where('feature_code', $featureCode)
            ->select('config')
            ->first();

        if ($companyRow && $companyRow->config) {
            $companyConfig = json_decode((string) $companyRow->config, true);
        }

        $companySettingsExtra = [];
        if ($this->tableExists('core', 'company_settings')) {
            $settingsRow = DB::table('core.company_settings')
                ->where('company_id', $companyId)
                ->select('extra_data')
                ->first();

            if ($settingsRow && $settingsRow->extra_data) {
                $decodedExtra = json_decode((string) $settingsRow->extra_data, true);
                if (is_array($decodedExtra)) {
                    $companySettingsExtra = $decodedExtra;
                }
            }
        }

        $cfg = array_merge(
            [
                'bridge_mode' => 'PRODUCTION',
                'production_url' => '',
                'beta_url' => '',
                'timeout_seconds' => 15,
                'auth_scheme' => 'none',
                'token' => '',
                'auto_send_on_issue' => true,
                'force_async_on_issue' => true,
                'sol_user' => '',
                'sol_pass' => '',
                'sunat_secondary_user' => '',
                'sunat_secondary_pass' => '',
                'client_id' => '',
                'client_secret' => '',
                'envio_pse' => '',
            ],
            is_array($companyConfig) ? $companyConfig : [],
            is_array($branchConfig) ? $branchConfig : []
        );

        $mode = strtoupper(trim((string) ($cfg['bridge_mode'] ?? 'PRODUCTION')));
        if (!in_array($mode, ['PRODUCTION', 'BETA'], true)) {
            $mode = 'PRODUCTION';
        }

        $rawBaseUrl = $mode === 'BETA'
            ? trim((string) ($cfg['beta_url'] ?? ''))
            : trim((string) ($cfg['production_url'] ?? ''));

        return [
            'enabled' => (bool) $enabled,
            'bridge_mode' => $mode,
            'raw_base_url' => $rawBaseUrl,
            'endpoint_url' => $this->resolveBridgeEndpoint($rawBaseUrl, 'send_xml'),
            'timeout_seconds' => max(5, min(60, (int) ($cfg['timeout_seconds'] ?? 15))),
            'auth_scheme' => strtolower(trim((string) ($cfg['auth_scheme'] ?? 'none'))),
            'token' => (string) ($cfg['token'] ?? ''),
            'force_async_on_issue' => isset($cfg['force_async_on_issue']) ? (bool) $cfg['force_async_on_issue'] : true,
            'auto_send_on_issue' => (bool) ($cfg['auto_send_on_issue'] ?? true),
            'auto_reconcile_enabled' => isset($cfg['auto_reconcile_enabled']) ? (bool) $cfg['auto_reconcile_enabled'] : true,
            'reconcile_batch_size' => max(5, min(50, (int) ($cfg['reconcile_batch_size'] ?? 20))),
            'sol_user' => trim((string) ($cfg['sol_user'] ?? '')),
            'sol_pass' => (string) ($cfg['sol_pass'] ?? ''),
            'sunat_secondary_user' => trim((string) (($companySettingsExtra['sunat_secondary_user'] ?? '') ?: ($cfg['sunat_secondary_user'] ?? ''))),
            'sunat_secondary_pass' => (string) (($companySettingsExtra['sunat_secondary_pass'] ?? '') !== ''
                ? $companySettingsExtra['sunat_secondary_pass']
                : ($cfg['sunat_secondary_pass'] ?? '')),
            'client_id' => trim((string) (($companySettingsExtra['client_id'] ?? '') ?: ($cfg['client_id'] ?? ''))),
            'client_secret' => (string) (($companySettingsExtra['client_secret'] ?? '') !== ''
                ? $companySettingsExtra['client_secret']
                : ($cfg['client_secret'] ?? '')),
            'envio_pse' => trim((string) ($cfg['envio_pse'] ?? '')),
            'codigolocal' => trim((string) ($cfg['codigolocal'] ?? '')),
        ];
    }

    private function resolveBridgeEndpoint(string $url, string $methodName): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $methodName = trim($methodName);
        if ($methodName === '') {
            return '';
        }

        $normalized = rtrim($url, '/');

        if (preg_match('#^(.*?/index\.php/sunat/)([^/?\#]+)(.*)$#i', $normalized, $matches) === 1) {
            return $matches[1] . $methodName . ($matches[3] ?? '');
        }

        if (preg_match('#^(.*?/sunat/)([^/?\#]+)(.*)$#i', $normalized, $matches) === 1) {
            return $matches[1] . $methodName . ($matches[3] ?? '');
        }

        if (preg_match('#/index\.php/sunat$#i', $normalized) === 1) {
            return $normalized . '/' . $methodName;
        }

        if (preg_match('#/sunat$#i', $normalized) === 1) {
            return $normalized . '/' . $methodName;
        }

        if (preg_match('#/index\.php$#i', $normalized) === 1) {
            return $normalized . '/Sunat/' . $methodName;
        }

        if (preg_match('#/index\.php/Sunat/[^/]+$#i', $normalized) === 1) {
            return preg_replace('#/[^/]+$#', '/' . $methodName, $normalized) ?: '';
        }

        if (preg_match('#/' . preg_quote($methodName, '#') . '$#i', $normalized) === 1) {
            return $normalized;
        }

        return $normalized . '/index.php/Sunat/' . $methodName;
    }

    private function resolveRegisterCertEndpoint(string $bridgeEndpoint): string
    {
        return $this->resolveBridgeEndpoint($bridgeEndpoint, 'register_CERT');
    }

    private function resolveLegacyResponseCode(string $rawBody, $decoded): ?int
    {
        if ($rawBody === '0' || $rawBody === '1' || $rawBody === '3') {
            return (int) $rawBody;
        }

        if (is_int($decoded) && in_array($decoded, [0, 1, 3], true)) {
            return $decoded;
        }

        if (is_string($decoded) && in_array($decoded, ['0', '1', '3'], true)) {
            return (int) $decoded;
        }

        if (is_array($decoded) && array_key_exists('res', $decoded) && in_array((int) $decoded['res'], [0, 1, 3], true)) {
            return (int) $decoded['res'];
        }

        return null;
    }

    public function manualConfirmWithEvidence(
        int $companyId,
        ?int $branchId,
        int $documentId,
        string $resolution,
        int $actorId,
        array $evidence
    ): array {
        $document = DB::table('sales.commercial_documents')
            ->where('id', $documentId)
            ->where('company_id', $companyId)
            ->first();

        if (!$document) {
            throw new TaxBridgeException('Documento no encontrado', 404);
        }

        if (!$this->supportsDocumentKind((string) $document->document_kind, isset($document->document_kind_id) ? (int) $document->document_kind_id : null)) {
            throw new TaxBridgeException('Solo se admite confirmacion manual para comprobantes tributarios', 422);
        }

        $normalizedResolution = strtoupper(trim($resolution));
        if (!in_array($normalizedResolution, ['ACCEPTED', 'REJECTED'], true)) {
            throw new TaxBridgeException('Resolucion manual invalida', 422);
        }

        $updates = [
            'sunat_status' => $normalizedResolution,
            'sunat_status_label' => $normalizedResolution === 'ACCEPTED'
                ? 'Confirmado manualmente con evidencia'
                : 'Rechazado manualmente con evidencia',
            'sunat_bridge_note' => 'Resolucion manual aplicada por usuario',
            'sunat_manual_confirmation_required' => false,
            'sunat_needs_manual_confirmation' => false,
            'sunat_reconcile_next_at' => null,
            'sunat_manual_confirmed_at' => now()->toDateTimeString(),
            'sunat_manual_confirmed_by' => $actorId,
            'sunat_manual_evidence' => [
                'type' => strtoupper(trim((string) ($evidence['type'] ?? 'OTHER'))),
                'reference' => trim((string) ($evidence['reference'] ?? '')),
                'note' => trim((string) ($evidence['note'] ?? '')),
            ],
        ];

        $this->updateDocumentTaxStatus($companyId, $documentId, $updates);

        if ($normalizedResolution === 'ACCEPTED') {
            $this->settleInventoryForAcceptedDocumentIfNeeded($companyId, $documentId);
        }

        return [
            'document_id' => $documentId,
            'sunat_status' => $normalizedResolution,
            'sunat_status_label' => $updates['sunat_status_label'],
            'inventory_sunat_settled' => $normalizedResolution === 'ACCEPTED',
        ];
    }

    public function notifyStaleSunatExceptions(int $hours, int $limit): array
    {
        $thresholdHours = max(1, min(168, $hours));
        $maxRows = max(1, min(500, $limit));
        $statusSet = ['PENDING_CONFIRMATION', 'EXPIRED_WINDOW', 'HTTP_ERROR', 'NETWORK_ERROR', 'ERROR'];

        $rows = DB::table('sales.commercial_documents')
            ->select('id', 'company_id', 'branch_id', 'document_kind', 'series', 'number', 'updated_at', 'metadata')
            ->whereIn(DB::raw("UPPER(COALESCE(metadata->>'sunat_status',''))"), $statusSet)
            ->whereRaw('EXTRACT(EPOCH FROM (NOW() - updated_at)) >= ?', [$thresholdHours * 3600])
            ->orderBy('updated_at')
            ->limit($maxRows)
            ->get();

        $emailSent = 0;
        $whatsappSent = 0;
        $notified = 0;

        foreach ($rows as $row) {
            $metadata = json_decode((string) ($row->metadata ?? '{}'), true);
            $metadata = is_array($metadata) ? $metadata : [];

            $repeatMinutes = max(10, (int) ($metadata['sunat_alert_repeat_minutes'] ?? 60));
            $lastAlertAt = trim((string) ($metadata['sunat_alert_last_at'] ?? ''));
            if ($lastAlertAt !== '') {
                try {
                    if (\Carbon\Carbon::parse($lastAlertAt)->addMinutes($repeatMinutes)->greaterThan(now())) {
                        continue;
                    }
                } catch (\Throwable $e) {
                    // Ignore malformed timestamp and continue.
                }
            }

            $config = $this->resolveConfig((int) $row->company_id, $row->branch_id !== null ? (int) $row->branch_id : null);
            $emails = $this->resolveAlertEmails((int) $row->company_id, $config);
            $whatsappWebhook = trim((string) ($config['alerts_whatsapp_webhook'] ?? ''));
            $status = strtoupper((string) ($metadata['sunat_status'] ?? 'PENDING_CONFIRMATION'));
            $hoursPending = max(0, (int) floor(now()->diffInMinutes(\Carbon\Carbon::parse((string) $row->updated_at)) / 60));

            $message = sprintf(
                'Excepcion SUNAT pendiente %dh: doc #%d %s %s-%s estado=%s',
                $hoursPending,
                (int) $row->id,
                (string) $row->document_kind,
                (string) $row->series,
                (string) $row->number,
                $status
            );

            $hasChannel = false;

            if (!empty($emails)) {
                try {
                    Mail::raw($message, function ($mail) use ($emails, $row, $status) {
                        $mail->to($emails)
                            ->subject(sprintf('Alerta SUNAT [%s] Documento #%d', $status, (int) $row->id));
                    });
                    $emailSent++;
                    $hasChannel = true;
                } catch (\Throwable $e) {
                    Log::warning('SUNAT alert email failed', [
                        'document_id' => (int) $row->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($whatsappWebhook !== '') {
                try {
                    Http::timeout(8)->post($whatsappWebhook, [
                        'text' => $message,
                        'document_id' => (int) $row->id,
                        'company_id' => (int) $row->company_id,
                        'status' => $status,
                    ]);
                    $whatsappSent++;
                    $hasChannel = true;
                } catch (\Throwable $e) {
                    Log::warning('SUNAT alert whatsapp failed', [
                        'document_id' => (int) $row->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (!$hasChannel) {
                Log::warning('SUNAT alert fallback log', [
                    'document_id' => (int) $row->id,
                    'message' => $message,
                ]);
            }

            $metadata['sunat_alert_last_at'] = now()->toDateTimeString();
            $metadata['sunat_alert_count'] = (int) ($metadata['sunat_alert_count'] ?? 0) + 1;

            DB::table('sales.commercial_documents')
                ->where('id', (int) $row->id)
                ->where('company_id', (int) $row->company_id)
                ->update([
                    'metadata' => json_encode($metadata),
                    'updated_at' => now(),
                ]);

            $notified++;
        }

        return [
            'candidates' => (int) $rows->count(),
            'notified' => $notified,
            'email' => $emailSent,
            'whatsapp' => $whatsappSent,
        ];
    }

    private function resolveAlertEmails(int $companyId, array $config): array
    {
        $emails = [];

        $cfgEmails = $config['alerts_email_to'] ?? [];
        if (is_string($cfgEmails) && trim($cfgEmails) !== '') {
            $cfgEmails = preg_split('/[,;\s]+/', trim($cfgEmails)) ?: [];
        }

        if (is_array($cfgEmails)) {
            foreach ($cfgEmails as $email) {
                $value = trim((string) $email);
                if ($value !== '') {
                    $emails[] = $value;
                }
            }
        }

        if (empty($emails) && $this->tableExists('core', 'company_settings')) {
            $settings = DB::table('core.company_settings')
                ->where('company_id', $companyId)
                ->select('email')
                ->first();

            $companyEmail = trim((string) ($settings->email ?? ''));
            if ($companyEmail !== '') {
                $emails[] = $companyEmail;
            }
        }

        return array_values(array_unique($emails));
    }

    private function isEnabledForContext(int $companyId, ?int $branchId, string $featureCode, bool $defaultEnabled): bool
    {
        if ($branchId !== null) {
            $branchRow = DB::table('appcfg.branch_feature_toggles')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('feature_code', $featureCode)
                ->select('is_enabled')
                ->first();

            if ($branchRow && $branchRow->is_enabled !== null) {
                return (bool) $branchRow->is_enabled;
            }
        }

        $companyRow = DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->where('feature_code', $featureCode)
            ->select('is_enabled')
            ->first();

        if ($companyRow && $companyRow->is_enabled !== null) {
            return (bool) $companyRow->is_enabled;
        }

        return $defaultEnabled;
    }

    private function tableExists(string $schema, string $table): bool
    {
        return DB::table('information_schema.tables')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->exists();
    }

    private function isOutsideSunatIssueWindow($issueAt): bool
    {
        if ($issueAt === null || trim((string) $issueAt) === '') {
            return false;
        }

        try {
            $issueDate = \Carbon\Carbon::parse((string) $issueAt)->startOfDay();
            $deadline = $issueDate->copy()->addDays(3)->endOfDay();
            return now()->greaterThan($deadline);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function updateDocumentTaxStatus(int $companyId, int $documentId, array $updates): void
    {
        $row = DB::table('sales.commercial_documents')
            ->where('id', $documentId)
            ->where('company_id', $companyId)
            ->select('metadata')
            ->first();

        if (!$row) {
            return;
        }

        $meta = json_decode((string) ($row->metadata ?? '{}'), true);
        if (!is_array($meta)) {
            $meta = [];
        }

        foreach ($updates as $key => $value) {
            if ($value === null) {
                unset($meta[$key]);
                continue;
            }

            $meta[$key] = $value;
        }

        $meta['sunat_last_sync_at'] = now()->toDateTimeString();

        DB::table('sales.commercial_documents')
            ->where('id', $documentId)
            ->where('company_id', $companyId)
            ->update([
                'metadata' => json_encode($meta),
                'updated_at' => now(),
            ]);
    }

    private function sanitizePayloadForDebug(array $payload): array
    {
        $sanitized = $payload;

        if (isset($sanitized['empresa']) && is_array($sanitized['empresa'])) {
            if (array_key_exists('pass', $sanitized['empresa'])) {
                $sanitized['empresa']['pass'] = '***';
            }
        }

        return $sanitized;
    }

    private function sanitizeCertPayloadForDebug(array $payload): array
    {
        $sanitized = $payload;

        if (array_key_exists('pass', $sanitized)) {
            $sanitized['pass'] = '***';
        }

        if (array_key_exists('pass_certificate', $sanitized)) {
            $sanitized['pass_certificate'] = '***';
        }

        return $sanitized;
    }

    private function nextVoidCommunicationNumber(int $companyId): int
    {
        $todayCount = DB::table('sales.commercial_documents')
            ->where('company_id', $companyId)
            ->whereDate('updated_at', now()->toDateString())
            ->count();

        return max(1, (int) $todayCount + 1);
    }

    public function settleInventoryForAcceptedDocumentIfNeeded(int $companyId, int $documentId): void
    {
        $document = DB::table('sales.commercial_documents')
            ->where('id', $documentId)
            ->where('company_id', $companyId)
            ->select('id', 'document_kind', 'series', 'number', 'issue_at', 'warehouse_id', 'metadata', 'updated_by')
            ->first();

        if (!$document) {
            return;
        }

        $kind = strtoupper((string) ($document->document_kind ?? ''));
        if (!in_array($kind, ['INVOICE', 'RECEIPT', 'CREDIT_NOTE', 'DEBIT_NOTE'], true)) {
            return;
        }

        $metadata = json_decode((string) ($document->metadata ?? '{}'), true);
        $metadata = is_array($metadata) ? $metadata : [];

        if (!empty($metadata['stock_already_discounted']) || !empty($metadata['inventory_sunat_settled'])) {
            return;
        }

        $direction = in_array($kind, ['INVOICE', 'RECEIPT', 'DEBIT_NOTE'], true) ? 'OUT' : 'IN';

        $items = DB::table('sales.commercial_document_items as i')
            ->leftJoin('inventory.products as p', 'p.id', '=', 'i.product_id')
            ->where('i.document_id', $documentId)
            ->select('i.id', 'i.product_id', 'i.qty', 'i.qty_base', 'i.conversion_factor', 'i.unit_cost', 'p.is_stockable')
            ->get();

        $movedAt = now();
        $createdBy = (int) ($document->updated_by ?? 0);
        $note = 'SUNAT aceptado ' . $kind . ' ' . (string) $document->series . '-' . (string) $document->number;

        foreach ($items as $item) {
            if ((int) ($item->product_id ?? 0) <= 0) {
                continue;
            }

            if (!(bool) ($item->is_stockable ?? false)) {
                continue;
            }

            $lots = DB::table('sales.commercial_document_item_lots')
                ->where('document_item_id', (int) $item->id)
                ->get(['lot_id', 'qty']);

            $payloadUnitCost = (float) ($item->unit_cost ?? 0);

            if ($lots->isNotEmpty()) {
                foreach ($lots as $lot) {
                    $qty = round((float) ($lot->qty ?? 0) * max((float) ($item->conversion_factor ?? 1), 0.00000001), 8);
                    if ($qty <= 0) {
                        continue;
                    }

                    $ledgerUnitCost = $payloadUnitCost;
                    if ($ledgerUnitCost <= 0 && $direction === 'OUT') {
                        $ledgerUnitCost = (float) (DB::table('inventory.product_lots')
                            ->where('id', (int) ($lot->lot_id ?? 0))
                            ->value('unit_cost') ?? 0);
                    }
                    if ($ledgerUnitCost <= 0 && $direction === 'OUT') {
                        $ledgerUnitCost = (float) (DB::table('inventory.products')
                            ->where('id', (int) $item->product_id)
                            ->value('cost_price') ?? 0);
                    }

                    DB::table('inventory.inventory_ledger')->insert([
                        'company_id' => $companyId,
                        'warehouse_id' => $document->warehouse_id !== null ? (int) $document->warehouse_id : null,
                        'product_id' => (int) $item->product_id,
                        'lot_id' => (int) ($lot->lot_id ?? 0) ?: null,
                        'movement_type' => $direction,
                        'quantity' => $qty,
                        'unit_cost' => $ledgerUnitCost,
                        'ref_type' => 'COMMERCIAL_DOCUMENT',
                        'ref_id' => $documentId,
                        'notes' => $note,
                        'moved_at' => $movedAt,
                        'created_by' => $createdBy,
                    ]);
                }
            } else {
                $qty = round((float) ($item->qty_base ?? $item->qty ?? 0), 8);
                if ($qty <= 0) {
                    continue;
                }

                $ledgerUnitCost = $payloadUnitCost;
                if ($ledgerUnitCost <= 0 && $direction === 'OUT') {
                    $ledgerUnitCost = (float) (DB::table('inventory.products')
                        ->where('id', (int) $item->product_id)
                        ->value('cost_price') ?? 0);
                }

                DB::table('inventory.inventory_ledger')->insert([
                    'company_id' => $companyId,
                    'warehouse_id' => $document->warehouse_id !== null ? (int) $document->warehouse_id : null,
                    'product_id' => (int) $item->product_id,
                    'lot_id' => null,
                    'movement_type' => $direction,
                    'quantity' => $qty,
                    'unit_cost' => $ledgerUnitCost,
                    'ref_type' => 'COMMERCIAL_DOCUMENT',
                    'ref_id' => $documentId,
                    'notes' => $note,
                    'moved_at' => $movedAt,
                    'created_by' => $createdBy,
                ]);
            }
        }

        $this->updateDocumentTaxStatus($companyId, $documentId, [
            'stock_already_discounted' => true,
            'inventory_sunat_settled' => true,
            'inventory_pending_sunat' => false,
            'inventory_sunat_settled_at' => now()->toDateTimeString(),
        ]);
    }

    public function reverseInventoryForVoidedDocumentIfNeeded(int $companyId, int $documentId): void
    {
        $row = DB::table('sales.commercial_documents')
            ->where('id', $documentId)
            ->where('company_id', $companyId)
            ->select('metadata', 'updated_by')
            ->first();

        if (!$row) {
            return;
        }

        $metadata = json_decode((string) ($row->metadata ?? '{}'), true);
        $metadata = is_array($metadata) ? $metadata : [];

        if (!empty($metadata['inventory_void_reverted'])) {
            return;
        }

        if (empty($metadata['stock_already_discounted']) && empty($metadata['inventory_sunat_settled'])) {
            return;
        }

        $ledgerRows = DB::table('inventory.inventory_ledger')
            ->where('company_id', $companyId)
            ->where('ref_type', 'COMMERCIAL_DOCUMENT')
            ->where('ref_id', $documentId)
            ->orderBy('id')
            ->get();

        foreach ($ledgerRows as $ledgerRow) {
            $originalType = strtoupper((string) ($ledgerRow->movement_type ?? ''));
            if (!in_array($originalType, ['IN', 'OUT'], true)) {
                continue;
            }

            $qty = round((float) ($ledgerRow->quantity ?? 0), 8);
            if ($qty <= 0) {
                continue;
            }

            $reverseType = $originalType === 'IN' ? 'OUT' : 'IN';

            DB::table('inventory.inventory_ledger')->insert([
                'company_id' => $companyId,
                'warehouse_id' => $ledgerRow->warehouse_id !== null ? (int) $ledgerRow->warehouse_id : null,
                'product_id' => (int) $ledgerRow->product_id,
                'lot_id' => $ledgerRow->lot_id !== null ? (int) $ledgerRow->lot_id : null,
                'movement_type' => $reverseType,
                'quantity' => $qty,
                'unit_cost' => (float) ($ledgerRow->unit_cost ?? 0),
                'ref_type' => 'COMMERCIAL_DOCUMENT_VOID',
                'ref_id' => $documentId,
                'notes' => 'Reversa por anulacion SUNAT doc #' . $documentId,
                'moved_at' => now(),
                'created_by' => (int) ($row->updated_by ?? 0),
            ]);
        }

        $this->updateDocumentTaxStatus($companyId, $documentId, [
            'inventory_void_reverted' => true,
            'inventory_void_reverted_at' => now()->toDateTimeString(),
            'stock_already_discounted' => false,
            'inventory_sunat_settled' => false,
            'inventory_pending_sunat' => false,
        ]);
    }

}
