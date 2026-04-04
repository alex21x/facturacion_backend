<?php

namespace App\Services\Sales\TaxBridge;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TaxBridgeService
{
    public function __construct(private TaxBridgePayloadBuilder $payloadBuilder)
    {
    }

    public function supportsDocumentKind(string $documentKind): bool
    {
        return in_array(strtoupper($documentKind), ['INVOICE', 'RECEIPT', 'CREDIT_NOTE', 'DEBIT_NOTE'], true);
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

        $this->performDispatch($companyId, $documentId, $config, false);
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

        if (!$this->supportsDocumentKind((string) $document->document_kind)) {
            throw new TaxBridgeException('Document type is not tributary (INVOICE/RECEIPT/CREDIT_NOTE/DEBIT_NOTE)', 422);
        }

        if (strtoupper((string) ($document->status ?? '')) !== 'ISSUED') {
            throw new TaxBridgeException('Document must be in ISSUED status to reattempt tax bridge send', 422);
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

        if (!$this->supportsDocumentKind((string) $document->document_kind)) {
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
            $request = Http::timeout((int) $config['timeout_seconds'])->acceptJson();

            if ($config['auth_scheme'] === 'bearer' && $config['token'] !== '') {
                $request = $request->withToken($config['token']);
            }

            $response = $request->asForm()->post($endpoint, [
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

            $bridgeResCode = $this->extractBridgeResponseCode($decoded);
            $bridgeState = strtoupper(trim($this->extractBridgeResponseState($decoded)));

            $status = 'SENT';
            $label = 'Comunicacion de baja enviada';

            if (!$response->successful()) {
                $status = 'HTTP_ERROR';
                $label = 'Error HTTP en comunicacion de baja';
            } elseif ($bridgeResCode === 1 || in_array($bridgeState, ['ACEPTADO', 'ACCEPTED', 'ENVIADO', 'OK'], true)) {
                $status = 'ACCEPTED';
                $label = 'Comunicacion de baja aceptada';
            } elseif ($bridgeResCode === 0 || in_array($bridgeState, ['RECHAZADO', 'REJECTED', 'ERROR'], true)) {
                $status = 'REJECTED';
                $label = 'Comunicacion de baja rechazada';
            }

            $this->updateDocumentTaxStatus($companyId, $documentId, [
                'sunat_void_status' => $status,
                'sunat_void_label' => $label,
                'sunat_void_http_code' => $response->status(),
                'sunat_void_response' => is_array($decoded) ? $decoded : ['raw' => substr($raw, 0, 1500)],
                'sunat_void_ticket' => is_array($decoded) ? ($decoded['ticket'] ?? null) : null,
            ]);

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

            throw new TaxBridgeException('SUNAT void communication failed: ' . $e->getMessage(), 500);
        }
    }

    public function getLastDispatchDebug(int $companyId, int $documentId): ?array
    {
        $document = DB::table('sales.commercial_documents')
            ->where('id', $documentId)
            ->where('company_id', $companyId)
            ->select('metadata')
            ->first();

        if (!$document) {
            return null;
        }

        $metadata = json_decode((string) ($document->metadata ?? '{}'), true);
        if (!is_array($metadata)) {
            return null;
        }

        $request = is_array($metadata['sunat_bridge_request'] ?? null)
            ? $metadata['sunat_bridge_request']
            : null;

        if ($request === null && empty($metadata['sunat_bridge_endpoint'])) {
            return null;
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
            $status = 'SENT';
            $label = 'Enviado';

            if (!$response->successful()) {
                $status = 'HTTP_ERROR';
                $label = 'Error HTTP';
            } elseif ($scalarBridgeCode === 1) {
                $status = 'ACCEPTED';
                $label = 'Aceptado';
            } elseif ($scalarBridgeCode === 0) {
                $status = 'REJECTED';
                $label = 'Rechazado';
            } elseif ($scalarBridgeCode === 3) {
                $status = 'ACCEPTED';
                $label = 'Aceptado';
            } elseif ($bridgeResCode === 1 || in_array($bridgeState, ['ACEPTADO', 'ACCEPTED', 'OK'], true)) {
                $status = 'ACCEPTED';
                $label = 'Aceptado';
            } elseif ($bridgeResCode === 0 || in_array($bridgeState, ['RECHAZADO', 'REJECTED', 'ERROR'], true)) {
                $status = 'REJECTED';
                $label = 'Rechazado';
            } elseif ($bridgeResCode === 3 || in_array($bridgeState, ['ANULADO', 'VOIDED'], true)) {
                // In some bridge implementations, code 3 represents a successful tributary terminal state.
                $status = 'ACCEPTED';
                $label = 'Aceptado';
            }

            $this->updateDocumentTaxStatus($companyId, $documentId, [
                'sunat_status' => $status,
                'sunat_status_label' => $label,
                'sunat_bridge_http_code' => $response->status(),
                'sunat_bridge_response' => is_array($decoded) ? $decoded : ['raw' => substr($raw, 0, 1500)],
                'sunat_ticket' => is_array($decoded) ? ($decoded['ticket'] ?? null) : null,
            ]);

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

            $this->updateDocumentTaxStatus($companyId, $documentId, [
                'sunat_status' => 'NETWORK_ERROR',
                'sunat_status_label' => 'Error red',
                'sunat_bridge_note' => substr($e->getMessage(), 0, 500),
            ]);

            if ($isRetry) {
                throw new TaxBridgeException('Tax bridge retry failed: ' . $e->getMessage(), 500);
            }

            return [
                'status' => 'NETWORK_ERROR',
                'label' => 'Error red',
            ];
        }
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
                'sol_user' => '',
                'sol_pass' => '',
                'sunat_secondary_user' => '',
                'sunat_secondary_pass' => '',
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
            'auto_send_on_issue' => (bool) ($cfg['auto_send_on_issue'] ?? true),
            'sol_user' => trim((string) ($cfg['sol_user'] ?? '')),
            'sol_pass' => (string) ($cfg['sol_pass'] ?? ''),
            'sunat_secondary_user' => trim((string) (($companySettingsExtra['sunat_secondary_user'] ?? '') ?: ($cfg['sunat_secondary_user'] ?? ''))),
            'sunat_secondary_pass' => (string) (($companySettingsExtra['sunat_secondary_pass'] ?? '') !== ''
                ? $companySettingsExtra['sunat_secondary_pass']
                : ($cfg['sunat_secondary_pass'] ?? '')),
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

}
