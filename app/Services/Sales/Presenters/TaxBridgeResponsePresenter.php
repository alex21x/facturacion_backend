<?php

namespace App\Services\Sales\Presenters;

class TaxBridgeResponsePresenter
{
    public function presentRetryResult(array $result, int $documentId, array $diagnostic): array
    {
        $status = strtoupper((string) ($result['status'] ?? ''));
        $isWafBlocked = (bool) ($result['waf_blocked'] ?? false);

        $payload = [
            'document_id' => $documentId,
            'sunat_status' => $result['status'] ?? null,
            'sunat_status_label' => $result['label'] ?? null,
            'bridge_http_code' => $result['bridge_http_code'] ?? null,
            'bridge_response' => $result['response'] ?? null,
            'sunat_error_code' => $diagnostic['code'] ?? null,
            'sunat_error_message' => $diagnostic['message'] ?? null,
            'debug' => $result['debug'] ?? null,
        ];

        if ($isWafBlocked || $status === 'WAF_BLOCKED') {
            return [
                'status' => 422,
                'payload' => array_merge($payload, [
                    'message' => 'Tax bridge retry blocked by Imunify360 bot-protection. Solicite whitelist de IP/automatizacion en el puente SUNAT.',
                ]),
            ];
        }

        if (in_array($status, ['REJECTED', 'ERROR', 'HTTP_ERROR'], true)) {
            return [
                'status' => 422,
                'payload' => array_merge($payload, [
                    'message' => 'Tax bridge retry failed',
                ]),
            ];
        }

        return [
            'status' => 200,
            'payload' => array_merge($payload, [
                'message' => 'Tax bridge retry sent successfully',
            ]),
        ];
    }

    public function presentSunatVoidResult(array $result, int $documentId, array $diagnostic): array
    {
        return [
            'message' => 'Comunicacion de baja SUNAT procesada',
            'document_id' => $documentId,
            'sunat_void_status' => $result['status'] ?? '',
            'sunat_void_label' => $result['label'] ?? '',
            'bridge_http_code' => $result['bridge_http_code'] ?? null,
            'bridge_response' => $result['response'] ?? null,
            'sunat_error_code' => $diagnostic['code'] ?? null,
            'sunat_error_message' => $diagnostic['message'] ?? null,
            'void_number' => $result['void_number'] ?? null,
            'debug' => $result['debug'] ?? null,
        ];
    }
}
