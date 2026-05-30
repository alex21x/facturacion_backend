<?php

namespace App\Infrastructure\External;

use App\Contracts\PadronLookupGateway;
use Illuminate\Support\Facades\Http;

class MundosoftPadronLookupGateway implements PadronLookupGateway
{
    public function consultDni(string $dni): array
    {
        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->get('https://mundosoftperu.com/reniec/consulta_reniec.php', ['dni' => $dni]);

            if (!$response->ok()) {
                return [
                    'ok' => false,
                    'status' => 502,
                    'message' => 'No se pudo consultar RENIEC.',
                ];
            }

            $json = $response->json();
            if (!is_array($json) || !isset($json[0]) || (string) $json[0] !== $dni) {
                return [
                    'ok' => false,
                    'status' => 404,
                    'message' => 'Numero no existe en RENIEC.',
                ];
            }

            $fullName = trim(implode(' ', array_filter([
                (string) ($json[2] ?? ''),
                (string) ($json[3] ?? ''),
                (string) ($json[1] ?? ''),
            ])));

            if ($fullName === '') {
                return [
                    'ok' => false,
                    'status' => 404,
                    'message' => 'RENIEC no devolvio nombre valido.',
                ];
            }

            return [
                'ok' => true,
                'status' => 200,
                'data' => [
                    'full_name' => $fullName,
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'status' => 502,
                'message' => 'Error al consultar padron externo.',
                'detail' => $e->getMessage(),
            ];
        }
    }

    public function consultRuc(string $ruc): array
    {
        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->get('https://mundosoftperu.com/sunat/sunat/consulta.php', ['nruc' => $ruc]);

            if (!$response->ok()) {
                return [
                    'ok' => false,
                    'status' => 502,
                    'message' => 'No se pudo consultar SUNAT.',
                ];
            }

            $json = $response->json();
            $result = is_array($json) ? ($json['result'] ?? null) : null;
            $resolvedRuc = is_array($result) ? (string) ($result['RUC'] ?? '') : '';
            $legalName = is_array($result) ? trim((string) ($result['RazonSocial'] ?? '')) : '';
            $address = is_array($result) ? trim((string) ($result['Direccion'] ?? '')) : '';

            if ($resolvedRuc !== $ruc || $legalName === '') {
                return [
                    'ok' => false,
                    'status' => 404,
                    'message' => 'Numero no existe en SUNAT.',
                ];
            }

            return [
                'ok' => true,
                'status' => 200,
                'data' => [
                    'legal_name' => $legalName,
                    'address' => $address !== '' ? $address : null,
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'status' => 502,
                'message' => 'Error al consultar padron externo.',
                'detail' => $e->getMessage(),
            ];
        }
    }
}
