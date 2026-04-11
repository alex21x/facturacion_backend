<?php

namespace App\Services\Sales\TaxBridge;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class GreGuideService
{
    private const STATUS_DRAFT = 'DRAFT';
    private const STATUS_SENDING = 'SENDING';
    private const STATUS_SENT = 'SENT';
    private const STATUS_ACCEPTED = 'ACCEPTED';
    private const STATUS_REJECTED = 'REJECTED';
    private const STATUS_ERROR = 'ERROR';
    private const STATUS_CANCELLED = 'CANCELLED';

    private array $activeVerticalCache = [];
    private array $verticalFeaturePreferenceCache = [];
    private array $featureResolutionCache = [];

    public function __construct(private TaxBridgeService $taxBridgeService)
    {
    }

    public function lookups(int $companyId): array
    {
        $taxBridgeFeature = $this->resolveFeatureResolutionForCompany($companyId, 'SALES_TAX_BRIDGE', false);

        $guideTypes = DB::table('sales.gre_guide_types')
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->get(['code', 'sunat_code', 'name'])
            ->values()
            ->all();

        $transferReasons = DB::table('sales.gre_transfer_reasons')
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->get(['code', 'name'])
            ->values()
            ->all();

        $transportModes = DB::table('sales.gre_transport_modes')
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->get(['code', 'name'])
            ->values()
            ->all();

        $documentTypes = DB::table('sales.customer_types')
            ->where('status', 1)
            ->orderBy('id')
            ->get([
                DB::raw("COALESCE(NULLIF(TRIM(CAST(sunat_code as text)), ''), '0') as code"),
                'name',
            ])
            ->values()
            ->all();

        $series = DB::table('core.series')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->whereRaw("UPPER(COALESCE(document_kind, '')) IN ('GUIDE', 'GRE', 'GUIA', 'GUIA_REMISION')")
            ->orderBy('series')
            ->get(['id', 'series', 'name'])
            ->values()
            ->all();

        if (empty($guideTypes)) {
            $guideTypes = [
                (object) ['code' => 'REMITENTE', 'sunat_code' => '01', 'name' => 'Guia de remitente'],
                (object) ['code' => 'TRANSPORTISTA', 'sunat_code' => '02', 'name' => 'Guia de transportista'],
            ];
        }

        if (empty($transferReasons)) {
            $transferReasons = [
                (object) ['code' => '01', 'name' => 'Venta'],
                (object) ['code' => '02', 'name' => 'Compra'],
                (object) ['code' => '04', 'name' => 'Traslado entre establecimientos de la misma empresa'],
                (object) ['code' => '08', 'name' => 'Importacion'],
                (object) ['code' => '09', 'name' => 'Exportacion'],
                (object) ['code' => '13', 'name' => 'Otros'],
                (object) ['code' => '14', 'name' => 'Venta sujeta a confirmacion del comprador'],
            ];
        }

        if (empty($transportModes)) {
            $transportModes = [
                (object) ['code' => '01', 'name' => 'Transporte publico'],
                (object) ['code' => '02', 'name' => 'Transporte privado'],
            ];
        }

        if (empty($documentTypes)) {
            $documentTypes = [
                (object) ['code' => '0', 'name' => 'DOC.TRIB.NO.DOM.SIN.RUC'],
                (object) ['code' => '1', 'name' => 'DNI'],
                (object) ['code' => '4', 'name' => 'Carnet de extranjeria'],
                (object) ['code' => '6', 'name' => 'RUC'],
                (object) ['code' => '7', 'name' => 'Pasaporte'],
            ];
        }

        return [
            'guide_types' => $guideTypes,
            'transfer_reasons' => $transferReasons,
            'transport_modes' => $transportModes,
            'document_types' => $documentTypes,
            'series' => $series,
            'runtime_features' => [
                [
                    'feature_code' => 'SALES_TAX_BRIDGE',
                    'is_enabled' => (bool) $taxBridgeFeature['is_enabled'],
                    'vertical_source' => $taxBridgeFeature['vertical_source'],
                ],
            ],
        ];
    }

    public function searchUbigeos(string $query, int $limit = 30): array
    {
        $q = trim($query);
        if ($q === '') {
            return [];
        }

        $limit = max(1, min(60, $limit));

        if ($this->tableExists('core.ubigeos')) {
            return DB::table('core.ubigeos')
                ->where(function ($sub) use ($q) {
                    $sub->where('code', 'ILIKE', $q . '%')
                        ->orWhere('department', 'ILIKE', '%' . $q . '%')
                        ->orWhere('province', 'ILIKE', '%' . $q . '%')
                        ->orWhere('district', 'ILIKE', '%' . $q . '%');
                })
                ->orderBy('code')
                ->limit($limit)
                ->get([
                    DB::raw('code as ubigeo'),
                    DB::raw("COALESCE(NULLIF(full_name,''), TRIM(code || ' - ' || COALESCE(district,'') || '|' || COALESCE(province,'') || '|DEPARTAMENTO ' || COALESCE(department,''))) as label"),
                    'department',
                    'province',
                    'district',
                ])
                ->values()
                ->all();
        }

        $source = $this->detectUbigeoSource();
        if ($source !== null) {
            return DB::table($source['table'])
                ->where(function ($sub) use ($q, $source) {
                    $sub->where($source['code'], 'ILIKE', $q . '%')
                        ->orWhere($source['department'], 'ILIKE', '%' . $q . '%')
                        ->orWhere($source['province'], 'ILIKE', '%' . $q . '%')
                        ->orWhere($source['district'], 'ILIKE', '%' . $q . '%');
                })
                ->orderBy($source['code'])
                ->limit($limit)
                ->get([
                    DB::raw($source['code'] . ' as ubigeo'),
                    DB::raw("TRIM(COALESCE(" . $source['district'] . ",'') || '|' || COALESCE(" . $source['province'] . ",'') || '|' || COALESCE(" . $source['department'] . ",'')) as label"),
                    DB::raw($source['department'] . ' as department'),
                    DB::raw($source['province'] . ' as province'),
                    DB::raw($source['district'] . ' as district'),
                ])
                ->values()
                ->all();
        }

        return [];
    }

    public function prefillFromCommercialDocument(int $companyId, int $documentId): array
    {
        $doc = DB::table('sales.commercial_documents as d')
            ->leftJoin('sales.customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('sales.customer_types as ct', 'ct.id', '=', 'c.customer_type_id')
            ->where('d.company_id', $companyId)
            ->where('d.id', $documentId)
            ->select([
                'd.id',
                'd.branch_id',
                'd.document_kind',
                'd.series',
                'd.number',
                'd.issue_at',
                'd.metadata',
                'c.doc_number as customer_doc_number',
                'c.legal_name as customer_legal_name',
                'c.first_name as customer_first_name',
                'c.last_name as customer_last_name',
                'c.address as customer_address',
                DB::raw("COALESCE(NULLIF(TRIM(CAST(ct.sunat_code as text)), ''), '0') as customer_doc_type"),
            ])
            ->first();

        if (!$doc) {
            throw new TaxBridgeException('Comprobante comercial no encontrado', 404);
        }

        $rawItems = DB::table('sales.commercial_document_items')
            ->where('document_id', (int) $doc->id)
            ->orderBy('line_no')
            ->get(['description', 'qty', 'metadata'])
            ->map(function ($item) {
                $meta = json_decode((string) ($item->metadata ?? '{}'), true);
                $meta = is_array($meta) ? $meta : [];

                return [
                    'code' => (string) ($meta['sku'] ?? $meta['code'] ?? ''),
                    'description' => (string) ($item->description ?? ''),
                    'qty' => (float) ($item->qty ?? 0),
                    'unit' => (string) ($meta['unit'] ?? 'NIU'),
                ];
            })
            ->values()
            ->all();

        $customerName = trim((string) ($doc->customer_legal_name ?? ''));
        if ($customerName === '') {
            $customerName = trim((string) ($doc->customer_first_name ?? '') . ' ' . (string) ($doc->customer_last_name ?? ''));
        }

        return [
            'related_document' => [
                'id' => (int) $doc->id,
                'document_kind' => (string) $doc->document_kind,
                'series' => (string) $doc->series,
                'number' => (int) $doc->number,
                'issue_at' => (string) $doc->issue_at,
            ],
            'draft' => [
                'branch_id' => $doc->branch_id !== null ? (int) $doc->branch_id : null,
                'guide_type' => 'REMITENTE',
                'issue_date' => date('Y-m-d'),
                'transfer_date' => date('Y-m-d'),
                'motivo_traslado' => '01',
                'transport_mode_code' => '02',
                'destinatario' => [
                    'doc_type' => (string) ($doc->customer_doc_type ?? '0'),
                    'doc_number' => (string) ($doc->customer_doc_number ?? ''),
                    'name' => $customerName,
                    'address' => (string) ($doc->customer_address ?? ''),
                ],
                'items' => $rawItems,
                'related_document_id' => (int) $doc->id,
            ],
        ];
    }

    public function prefillFromCommercialDocumentRef(int $companyId, string $series, int $number, ?string $documentKind = null): array
    {
        $query = DB::table('sales.commercial_documents')
            ->where('company_id', $companyId)
            ->where('series', strtoupper(trim($series)))
            ->where('number', $number);

        if ($documentKind !== null && trim($documentKind) !== '') {
            $query->where('document_kind', strtoupper(trim($documentKind)));
        }

        $docId = (int) ($query->value('id') ?? 0);
        if ($docId <= 0) {
            throw new TaxBridgeException('No se encontro comprobante con esa serie y numero', 404);
        }

        return $this->prefillFromCommercialDocument($companyId, $docId);
    }

    public function list(int $companyId, array $filters, int $page, int $perPage): array
    {
        $query = DB::table('sales.gre_guides as gg')
            ->where('gg.company_id', $companyId)
            ->orderByDesc('gg.issue_date')
            ->orderByDesc('gg.id');

        if (!empty($filters['status'])) {
            $query->where('gg.status', strtoupper((string) $filters['status']));
        }

        if (!empty($filters['issue_date'])) {
            $query->whereDate('gg.issue_date', (string) $filters['issue_date']);
        }

        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('gg.identifier', 'ILIKE', '%' . $search . '%')
                    ->orWhere('gg.series', 'ILIKE', '%' . $search . '%')
                    ->orWhereRaw("CAST(gg.number AS TEXT) ILIKE ?", ['%' . $search . '%'])
                    ->orWhereRaw("COALESCE(gg.destinatario->>'doc_number','') ILIKE ?", ['%' . $search . '%'])
                    ->orWhereRaw("COALESCE(gg.destinatario->>'name','') ILIKE ?", ['%' . $search . '%']);
            });
        }

        $total = (clone $query)->count();
        $rows = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->select(
                'gg.id',
                'gg.guide_type',
                'gg.issue_date',
                'gg.transfer_date',
                'gg.series',
                'gg.number',
                'gg.identifier',
                'gg.status',
                'gg.motivo_traslado',
                'gg.punto_partida',
                'gg.punto_llegada',
                'gg.sunat_ticket',
                'gg.sunat_cdr_code',
                'gg.sunat_cdr_desc',
                'gg.raw_response',
                DB::raw("CASE
                    WHEN COALESCE(gg.sunat_cdr_code,'') <> '' THEN
                        CASE WHEN COALESCE(NULLIF(regexp_replace(gg.sunat_cdr_code, '[^0-9]', '', 'g'), ''), '0')::int BETWEEN 2000 AND 3999 THEN 'RECHAZADO'
                             ELSE 'ACEPTADO'
                        END
                    WHEN gg.status = 'SENT' THEN 'PENDIENTE_TICKET'
                    WHEN COALESCE(gg.sunat_ticket,'') <> '' THEN 'PENDIENTE_TICKET'
                    ELSE 'SIN_ENVIO'
                 END as sunat_status"),
                DB::raw("COALESCE(jsonb_array_length(COALESCE(gg.items, '[]'::jsonb)), 0) as item_count"),
                'gg.sent_at',
                'gg.cancelled_at',
                'gg.created_at',
                'gg.updated_at'
            )
            ->get();

        $rows = $rows->map(function ($row) use ($companyId) {
            $ticket = trim((string) ($row->sunat_ticket ?? ''));
            if ($ticket === '') {
                $rawResponse = is_string($row->raw_response) ? $row->raw_response : '';
                $decoded = is_string($row->raw_response) ? json_decode($row->raw_response, true) : null;
                $decoded = is_array($decoded) ? $decoded : [];
                $fallbackTicket = $this->extractBridgeTicket($decoded, $rawResponse);
                if ($fallbackTicket !== null && $fallbackTicket !== '') {
                    $row->sunat_ticket = $fallbackTicket;
                    if (strtoupper((string) ($row->sunat_status ?? '')) === 'SIN_ENVIO') {
                        $row->sunat_status = 'PENDIENTE_TICKET';
                    }

                    // Auto-heal historical rows that were sent without persisting ticket.
                    DB::table('sales.gre_guides')
                        ->where('company_id', $companyId)
                        ->where('id', (int) $row->id)
                        ->update([
                            'sunat_ticket' => $fallbackTicket,
                            'updated_at' => now(),
                        ]);
                }
            }

            unset($row->raw_response);
            return $row;
        });

        return [
            'data' => $rows->values()->all(),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) ceil($total / max(1, $perPage)),
            ],
        ];
    }

    public function show(int $companyId, int $guideId): ?array
    {
        $row = DB::table('sales.gre_guides')
            ->where('company_id', $companyId)
            ->where('id', $guideId)
            ->first();

        if (!$row) {
            return null;
        }

        return $this->normalizeGuideRow($row);
    }

    public function create(int $companyId, array $payload, int $userId): array
    {
        $this->enforceBusinessRules($payload);

        $issueDate = (string) $payload['issue_date'];
        $series = strtoupper(trim((string) ($payload['series'] ?? 'T001')));
        $number = $this->nextNumber($companyId, $series);
        $identifier = sprintf('%s-%08d', $series, $number);

        $guideId = DB::table('sales.gre_guides')->insertGetId([
            'company_id' => $companyId,
            'branch_id' => $payload['branch_id'] ?? null,
            'guide_type' => strtoupper((string) ($payload['guide_type'] ?? 'REMITENTE')),
            'issue_date' => $issueDate,
            'transfer_date' => $payload['transfer_date'] ?? null,
            'series' => $series,
            'number' => $number,
            'identifier' => $identifier,
            'status' => self::STATUS_DRAFT,
            'notes' => $payload['notes'] ?? null,
            'motivo_traslado' => (string) ($payload['motivo_traslado'] ?? '01'),
            'transport_mode_code' => (string) ($payload['transport_mode_code'] ?? '02'),
            'weight_kg' => (float) ($payload['weight_kg'] ?? 0),
            'packages_count' => (int) ($payload['packages_count'] ?? 1),
            'punto_partida' => (string) ($payload['punto_partida'] ?? ''),
            'punto_llegada' => (string) ($payload['punto_llegada'] ?? ''),
            'partida_ubigeo' => $payload['partida_ubigeo'] ?? null,
            'llegada_ubigeo' => $payload['llegada_ubigeo'] ?? null,
            'related_document_id' => $payload['related_document_id'] ?? null,
            'transporter' => json_encode($payload['transporter'] ?? null),
            'vehicle' => json_encode($payload['vehicle'] ?? null),
            'driver' => json_encode($payload['driver'] ?? null),
            'destinatario' => json_encode($payload['destinatario'] ?? null),
            'items' => json_encode($payload['items'] ?? []),
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->show($companyId, (int) $guideId) ?? [];
    }

    public function update(int $companyId, int $guideId, array $payload, int $userId): array
    {
        $row = DB::table('sales.gre_guides')
            ->where('company_id', $companyId)
            ->where('id', $guideId)
            ->first();

        if (!$row) {
            throw new TaxBridgeException('Guia GRE no encontrada', 404);
        }

        if (!in_array((string) $row->status, [self::STATUS_DRAFT, self::STATUS_ERROR, self::STATUS_REJECTED], true)) {
            throw new TaxBridgeException('Solo guias en DRAFT, ERROR o REJECTED pueden editarse', 422);
        }

        $effectivePayload = $this->buildEffectivePayloadForValidation($row, $payload);
        $this->enforceBusinessRules($effectivePayload);

        DB::table('sales.gre_guides')
            ->where('id', $guideId)
            ->where('company_id', $companyId)
            ->update([
                'guide_type' => strtoupper((string) ($payload['guide_type'] ?? $row->guide_type)),
                'issue_date' => $payload['issue_date'] ?? $row->issue_date,
                'transfer_date' => $payload['transfer_date'] ?? $row->transfer_date,
                'notes' => $payload['notes'] ?? $row->notes,
                'motivo_traslado' => (string) ($payload['motivo_traslado'] ?? $row->motivo_traslado),
                'transport_mode_code' => (string) ($payload['transport_mode_code'] ?? $row->transport_mode_code ?? '02'),
                'weight_kg' => isset($payload['weight_kg']) ? (float) $payload['weight_kg'] : $row->weight_kg,
                'packages_count' => isset($payload['packages_count']) ? (int) $payload['packages_count'] : $row->packages_count,
                'punto_partida' => (string) ($payload['punto_partida'] ?? $row->punto_partida),
                'punto_llegada' => (string) ($payload['punto_llegada'] ?? $row->punto_llegada),
                'partida_ubigeo' => array_key_exists('partida_ubigeo', $payload) ? $payload['partida_ubigeo'] : $row->partida_ubigeo,
                'llegada_ubigeo' => array_key_exists('llegada_ubigeo', $payload) ? $payload['llegada_ubigeo'] : $row->llegada_ubigeo,
                'related_document_id' => array_key_exists('related_document_id', $payload) ? $payload['related_document_id'] : $row->related_document_id,
                'transporter' => array_key_exists('transporter', $payload) ? json_encode($payload['transporter']) : $row->transporter,
                'vehicle' => array_key_exists('vehicle', $payload) ? json_encode($payload['vehicle']) : $row->vehicle,
                'driver' => array_key_exists('driver', $payload) ? json_encode($payload['driver']) : $row->driver,
                'destinatario' => array_key_exists('destinatario', $payload) ? json_encode($payload['destinatario']) : $row->destinatario,
                'items' => array_key_exists('items', $payload) ? json_encode($payload['items']) : $row->items,
                'updated_by' => $userId,
                'updated_at' => now(),
            ]);

        return $this->show($companyId, $guideId) ?? [];
    }

    public function send(int $companyId, int $guideId): array
    {
        $row = DB::table('sales.gre_guides')
            ->where('company_id', $companyId)
            ->where('id', $guideId)
            ->first();

        if (!$row) {
            throw new TaxBridgeException('Guia GRE no encontrada', 404);
        }

        if (in_array((string) $row->status, [self::STATUS_CANCELLED, self::STATUS_ACCEPTED], true)) {
            throw new TaxBridgeException('La guia no se puede enviar en su estado actual', 422);
        }

        $this->enforceBusinessRules($this->buildEffectivePayloadForValidation($row, []));

        $config = $this->taxBridgeService->resolvePublicConfig($companyId, $row->branch_id !== null ? (int) $row->branch_id : null);
        if (!(bool) ($config['enabled'] ?? false)) {
            throw new TaxBridgeException('Tax bridge no habilitado', 422);
        }

        if (strtoupper((string) $row->guide_type) !== 'REMITENTE') {
            throw new TaxBridgeException('Por ahora el envio GRE solo esta habilitado para guia de remitente', 422);
        }

        $bridgeMethod = trim((string) env('TAX_BRIDGE_GRE_METHOD', 'send_guiaRemision'));
        $endpoint = $this->resolveBridgeEndpoint((string) ($config['raw_base_url'] ?? ''), $bridgeMethod);
        if ($endpoint === '') {
            throw new TaxBridgeException('No se pudo resolver endpoint GRE', 422);
        }

        $payload = $this->buildGrePayload($companyId, $row, $config);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($payloadJson)) {
            $payloadJson = '{}';
        }

        DB::table('sales.gre_guides')
            ->where('id', $guideId)
            ->where('company_id', $companyId)
            ->update([
                'status' => self::STATUS_SENDING,
                'bridge_method' => $bridgeMethod,
                'bridge_endpoint' => $endpoint,
                'sent_at' => now(),
                'updated_at' => now(),
            ]);

        try {
            $httpReq = Http::timeout((int) ($config['timeout_seconds'] ?? 30))->acceptJson();
            if (($config['auth_scheme'] ?? '') === 'bearer' && !empty($config['token'])) {
                $httpReq = $httpReq->withToken((string) $config['token']);
            }

            $response = $httpReq->asForm()->post($endpoint, ['datosJSON' => $payloadJson]);

            $raw = (string) $response->body();
            $decoded = json_decode($raw, true);
            if (is_string($decoded)) {
                $nested = json_decode($decoded, true);
                if (is_array($nested)) {
                    $decoded = $nested;
                }
            }

            $status = $response->successful() ? self::STATUS_SENT : self::STATUS_ERROR;
            $label = $response->successful() ? 'Guia enviada' : 'Error de envio';

            $bridgeRes = is_array($decoded) && isset($decoded['res']) ? (int) $decoded['res'] : null;
            $ticket = $this->extractBridgeTicket($decoded, $raw);
            $cdrCode = $this->extractBridgeCode($decoded);
            $cdrDesc = $this->extractBridgeMessage($decoded);

            if ($response->successful() && $bridgeRes === 0 && $ticket === null) {
                $status = self::STATUS_ERROR;
                $label = 'Error de envio';
            } elseif ($response->successful() && $ticket !== null && $ticket !== '') {
                $status = self::STATUS_SENT;
                $label = 'Ticket generado';
            } elseif ($response->successful() && $cdrCode !== null && ctype_digit($cdrCode)) {
                $cdrCodeInt = (int) $cdrCode;
                if ($cdrCodeInt === 0 || $cdrCodeInt >= 4000) {
                    $status = self::STATUS_ACCEPTED;
                    $label = 'Guia aceptada';
                } elseif ($cdrCodeInt >= 2000 && $cdrCodeInt <= 3999) {
                    $status = self::STATUS_REJECTED;
                    $label = 'Guia rechazada';
                }
            } elseif ($response->successful() && $ticket === null) {
                $status = self::STATUS_ERROR;
                $label = 'Envio sin ticket';
                if ($cdrDesc === null) {
                    $cdrDesc = 'El puente no devolvio ticket SUNAT para consultar estado';
                }
            }

            DB::table('sales.gre_guides')
                ->where('id', $guideId)
                ->where('company_id', $companyId)
                ->update([
                    'status' => $status,
                    'bridge_http_code' => $response->status(),
                    'sunat_ticket' => $ticket,
                    'sunat_cdr_code' => $cdrCode,
                    'sunat_cdr_desc' => $cdrDesc,
                    'raw_response' => json_encode(is_array($decoded) ? $decoded : ['raw' => substr($raw, 0, 2000)]),
                    'updated_at' => now(),
                ]);

            return [
                'status' => $status,
                'label' => $label,
                'bridge_http_code' => $response->status(),
                'sunat_ticket' => $ticket,
                'sunat_cdr_code' => $cdrCode,
                'sunat_cdr_desc' => $cdrDesc,
                'response' => is_array($decoded) ? $decoded : ['raw' => substr($raw, 0, 2000)],
                'debug' => [
                    'endpoint' => $endpoint,
                    'bridge_method' => $bridgeMethod,
                    'payload' => $payload,
                ],
            ];
        } catch (\Throwable $e) {
            DB::table('sales.gre_guides')
                ->where('id', $guideId)
                ->where('company_id', $companyId)
                ->update([
                    'status' => self::STATUS_ERROR,
                    'raw_response' => json_encode(['error' => substr($e->getMessage(), 0, 500)]),
                    'updated_at' => now(),
                ]);

            throw new TaxBridgeException('No se pudo enviar la GRE: ' . $e->getMessage(), 500);
        }
    }

    public function queryTicketStatus(int $companyId, int $guideId): array
    {
        $row = DB::table('sales.gre_guides')
            ->where('company_id', $companyId)
            ->where('id', $guideId)
            ->first();

        if (!$row) {
            throw new TaxBridgeException('Guia GRE no encontrada', 404);
        }

        $ticket = trim((string) ($row->sunat_ticket ?? ''));
        if ($ticket === '') {
            throw new TaxBridgeException('La guia no tiene ticket SUNAT para consultar', 422);
        }

        $config = $this->taxBridgeService->resolvePublicConfig($companyId, $row->branch_id !== null ? (int) $row->branch_id : null);
        if (!(bool) ($config['enabled'] ?? false)) {
            throw new TaxBridgeException('Tax bridge no habilitado', 422);
        }

        $bridgeMethod = trim((string) env('TAX_BRIDGE_GRE_STATUS_METHOD', 'send_statusTicketGRE'));
        $endpoint = $this->resolveBridgeEndpoint((string) ($config['raw_base_url'] ?? ''), $bridgeMethod);
        if ($endpoint === '') {
            throw new TaxBridgeException('No se pudo resolver endpoint de estado GRE', 422);
        }

        $payload = [
            'empresa' => $this->buildCompanyStatusAuthBlock($companyId, $config),
            'cabecera' => [
                'ticket' => $ticket,
                'tipo_documento' => '09',
                'guia_serie' => (string) ($row->series ?? ''),
                'guia_numero' => str_pad((string) ($row->number ?? 0), 8, '0', STR_PAD_LEFT),
            ],
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($payloadJson)) {
            $payloadJson = '{}';
        }

        $httpReq = Http::timeout((int) ($config['timeout_seconds'] ?? 30))->acceptJson();
        if (($config['auth_scheme'] ?? '') === 'bearer' && !empty($config['token'])) {
            $httpReq = $httpReq->withToken((string) $config['token']);
        }

        $response = $httpReq->asForm()->post($endpoint, ['datosJSON' => $payloadJson]);
        $raw = (string) $response->body();
        $decoded = json_decode($raw, true);
        if (is_string($decoded)) {
            $nested = json_decode($decoded, true);
            if (is_array($nested)) {
                $decoded = $nested;
            }
        }

        $cdrCode = $this->extractBridgeCode($decoded) ?? '';
        $cdrDesc = $this->extractBridgeMessage($decoded) ?? '';
        $cdrLink = $this->extractBridgeField($decoded, ['link', 'reference', 'cdr_link', 'cdrReference']) ?? '';

        if ($cdrDesc === '' && $cdrLink !== '') {
            $cdrDesc = 'Referencia: ' . $cdrLink;
        }

        $status = self::STATUS_SENT;
        $label = 'Ticket en proceso';
        if ($cdrCode !== '' && ctype_digit($cdrCode)) {
            $codeInt = (int) $cdrCode;
            if ($codeInt === 0 || $codeInt >= 4000) {
                $status = self::STATUS_ACCEPTED;
                $label = 'Guia aceptada';
            } elseif ($codeInt >= 2000 && $codeInt <= 3999) {
                $status = self::STATUS_REJECTED;
                $label = 'Guia rechazada';
            }
        }

        DB::table('sales.gre_guides')
            ->where('id', $guideId)
            ->where('company_id', $companyId)
            ->update([
                'status' => $status,
                'bridge_method' => $bridgeMethod,
                'bridge_endpoint' => $endpoint,
                'bridge_http_code' => $response->status(),
                'sunat_cdr_code' => $cdrCode !== '' ? $cdrCode : null,
                'sunat_cdr_desc' => $cdrDesc !== '' ? $cdrDesc : null,
                'raw_response' => json_encode(is_array($decoded) ? $decoded : ['raw' => substr($raw, 0, 2000)]),
                'updated_at' => now(),
            ]);

        return [
            'status' => $status,
            'label' => $label,
            'bridge_http_code' => $response->status(),
            'sunat_ticket' => $ticket,
            'sunat_cdr_code' => $cdrCode !== '' ? $cdrCode : null,
            'sunat_cdr_desc' => $cdrDesc !== '' ? $cdrDesc : null,
            'response' => is_array($decoded) ? $decoded : ['raw' => substr($raw, 0, 2000)],
            'debug' => [
                'endpoint' => $endpoint,
                'bridge_method' => $bridgeMethod,
                'payload' => $payload,
            ],
        ];
    }

    private function buildCompanyStatusAuthBlock(int $companyId, array $config): array
    {
        $empresa = $this->buildCompanyAuthBlock($companyId, $config);
        $empresa['client_id'] = (string) (($config['client_id'] ?? '') !== ''
            ? $config['client_id']
            : env('TAX_BRIDGE_GRE_CLIENT_ID', '70bae3cc-53cc-49c5-a69e-d2d6d1090e93'));
        $empresa['client_secret'] = (string) (($config['client_secret'] ?? '') !== ''
            ? $config['client_secret']
            : env('TAX_BRIDGE_GRE_CLIENT_SECRET', 'd3zcun+948VLlHWCm9djig=='));

        return $empresa;
    }

    private function extractBridgeTicket($decoded, string $raw): ?string
    {
        $ticket = $this->extractBridgeField($decoded, ['ticket', 'Ticket', 'nroTicket', 'idTicket']);
        if ($ticket !== null) {
            return $ticket;
        }

        if ($raw !== '' && preg_match('/\b([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})\b/i', $raw, $m) === 1) {
            return trim((string) $m[1]);
        }

        if (is_array($decoded)) {
            $msg = $this->extractBridgeMessage($decoded) ?? '';
            if ($msg !== '' && preg_match('/ticket[^A-Za-z0-9]*([A-Za-z0-9\-]{10,})/i', $msg, $m) === 1) {
                return trim((string) $m[1]);
            }
        }

        return null;
    }

    private function extractBridgeCode($decoded): ?string
    {
        return $this->extractBridgeField($decoded, ['codRespuesta', 'code', 'errorCode', 'cdr_code']);
    }

    private function extractBridgeMessage($decoded): ?string
    {
        return $this->extractBridgeField($decoded, ['desRespuesta', 'msg', 'errorMessage', 'cdr_desc', 'message']);
    }

    private function extractBridgeField($value, array $keys): ?string
    {
        if (!is_array($value)) {
            return null;
        }

        foreach ($keys as $key) {
            if (array_key_exists($key, $value)) {
                $candidate = trim((string) ($value[$key] ?? ''));
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        foreach ($value as $child) {
            if (is_array($child)) {
                $nested = $this->extractBridgeField($child, $keys);
                if ($nested !== null && $nested !== '') {
                    return $nested;
                }
            }
        }

        return null;
    }

    public function cancel(int $companyId, int $guideId, string $reason, int $userId): array
    {
        $row = DB::table('sales.gre_guides')
            ->where('company_id', $companyId)
            ->where('id', $guideId)
            ->first();

        if (!$row) {
            throw new TaxBridgeException('Guia GRE no encontrada', 404);
        }

        if ((string) $row->status === self::STATUS_CANCELLED) {
            throw new TaxBridgeException('La guia ya esta anulada', 422);
        }

        DB::table('sales.gre_guides')
            ->where('id', $guideId)
            ->where('company_id', $companyId)
            ->update([
                'status' => self::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_reason' => $reason,
                'updated_by' => $userId,
                'updated_at' => now(),
            ]);

        return $this->show($companyId, $guideId) ?? [];
    }

    public function printableHtml(int $companyId, int $guideId, string $format = 'a4'): string
    {
        $guide = $this->show($companyId, $guideId);
        if (!$guide) {
            throw new TaxBridgeException('Guia GRE no encontrada', 404);
        }

        // Company data
        $company        = \DB::table('core.companies')->where('id', $companyId)->first(['tax_id', 'legal_name', 'trade_name', 'address']);
        $companyRuc     = htmlspecialchars((string) ($company->tax_id ?? ''), ENT_QUOTES, 'UTF-8');
        $companyName    = htmlspecialchars((string) ($company->legal_name ?? 'EMPRESA'), ENT_QUOTES, 'UTF-8');
        $companyTrade   = htmlspecialchars((string) ($company->trade_name ?? ''), ENT_QUOTES, 'UTF-8');
        $companyAddress = htmlspecialchars((string) ($company->address ?? ''), ENT_QUOTES, 'UTF-8');

        $items        = $guide['items'] ?? [];
        $identifier   = htmlspecialchars((string) ($guide['identifier'] ?? ''), ENT_QUOTES, 'UTF-8');
        $issueDate    = htmlspecialchars((string) ($guide['issue_date'] ?? ''), ENT_QUOTES, 'UTF-8');
        $transferDate = htmlspecialchars((string) ($guide['transfer_date'] ?? $guide['issue_date'] ?? ''), ENT_QUOTES, 'UTF-8');
        $destName     = htmlspecialchars((string) (($guide['destinatario']['name'] ?? '') ?: '-'), ENT_QUOTES, 'UTF-8');
        $destDoc      = htmlspecialchars((string) (($guide['destinatario']['doc_number'] ?? '') ?: '-'), ENT_QUOTES, 'UTF-8');
        $destAddress  = htmlspecialchars((string) (($guide['destinatario']['address'] ?? '') ?: '-'), ENT_QUOTES, 'UTF-8');
        $partida      = htmlspecialchars((string) ($guide['punto_partida'] ?? '-'), ENT_QUOTES, 'UTF-8');
        $llegada      = htmlspecialchars((string) ($guide['punto_llegada'] ?? '-'), ENT_QUOTES, 'UTF-8');
        $ticket       = htmlspecialchars((string) ($guide['sunat_ticket'] ?? ''), ENT_QUOTES, 'UTF-8');
        $cdrCode      = htmlspecialchars((string) ($guide['sunat_cdr_code'] ?? ''), ENT_QUOTES, 'UTF-8');
        $cdrDesc      = htmlspecialchars((string) ($guide['sunat_cdr_desc'] ?? ''), ENT_QUOTES, 'UTF-8');
        $sunatLinkRaw = $this->resolveGreSunatConstancyLink(
            is_array($guide['raw_response'] ?? null) ? $guide['raw_response'] : [],
            (string) ($guide['sunat_cdr_desc'] ?? '')
        );
        $sunatLink = htmlspecialchars($sunatLinkRaw, ENT_QUOTES, 'UTF-8');
        $sunatQr = $sunatLinkRaw !== ''
            ? htmlspecialchars('https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' . urlencode($sunatLinkRaw), ENT_QUOTES, 'UTF-8')
            : '';
        $weightKg     = number_format((float) ($guide['weight_kg'] ?? 0), 3);
        $packages     = (int) ($guide['packages_count'] ?? 0);
        $motivo       = htmlspecialchars((string) ($guide['motivo_traslado'] ?? ''), ENT_QUOTES, 'UTF-8');
        $guideType    = htmlspecialchars((string) ($guide['guide_type'] ?? 'REMITENTE'), ENT_QUOTES, 'UTF-8');
        $modeCode     = (string) ($guide['transport_mode_code'] ?? '02');
        $modoLabel    = $modeCode === '01' ? 'Publico' : 'Privado';
        $status       = htmlspecialchars((string) ($guide['status'] ?? ''), ENT_QUOTES, 'UTF-8');

        // Transport details
        $vehiclePlaca    = htmlspecialchars((string) (($guide['vehicle']['placa'] ?? '') ?: ''), ENT_QUOTES, 'UTF-8');
        $driverName      = htmlspecialchars((string) (($guide['driver']['name'] ?? '') ?: ''), ENT_QUOTES, 'UTF-8');
        $driverDoc       = htmlspecialchars((string) (($guide['driver']['doc_number'] ?? '') ?: ''), ENT_QUOTES, 'UTF-8');
        $transporterName = htmlspecialchars((string) (($guide['transporter']['name'] ?? '') ?: ''), ENT_QUOTES, 'UTF-8');
        $transporterDoc  = htmlspecialchars((string) (($guide['transporter']['doc_number'] ?? '') ?: ''), ENT_QUOTES, 'UTF-8');

        // ── 80mm Ticket ──────────────────────────────────────────────────────────
        if ($format === 'ticket') {
            $itemRows = '';
            foreach ($items as $item) {
                $desc = htmlspecialchars((string) ($item['description'] ?? ''), ENT_QUOTES, 'UTF-8');
                $qty  = number_format((float) ($item['qty'] ?? 0), 2);
                $unit = htmlspecialchars((string) ($item['unit'] ?? ''), ENT_QUOTES, 'UTF-8');
                $code = htmlspecialchars((string) ($item['code'] ?? ''), ENT_QUOTES, 'UTF-8');
                $itemRows .= "<tr><td style=\"font-size:8px;font-weight:700\">{$desc}</td></tr>\n";
                $itemRows .= "<tr><td style=\"font-size:7px\"><div style=\"display:flex;justify-content:space-between\"><span style=\"color:#555\">{$code} {$unit}</span><span style=\"font-weight:700\">{$qty}</span></div></td></tr>\n";
            }
            if ($itemRows === '') {
                $itemRows = '<tr><td style="text-align:center;font-size:8px">Sin items</td></tr>';
            }

            $transportInfo = '';
            if ($modoLabel === 'Publico' && $transporterName !== '') {
                $transportInfo = "<div class=\"info-row\"><span class=\"info-label\">TRANSPORTISTA:</span><span class=\"info-value\">{$transporterName}</span></div>"
                    . "<div class=\"info-row\"><span class=\"info-label\">RUC:</span><span class=\"info-value\">{$transporterDoc}</span></div>";
            } elseif ($modoLabel === 'Privado') {
                if ($vehiclePlaca !== '') {
                    $transportInfo .= "<div class=\"info-row\"><span class=\"info-label\">VEHICULO:</span><span class=\"info-value\">{$vehiclePlaca}</span></div>";
                }
                if ($driverName !== '') {
                    $transportInfo .= "<div class=\"info-row\"><span class=\"info-label\">CONDUCTOR:</span><span class=\"info-value\">{$driverName}</span></div>"
                        . "<div class=\"info-row\"><span class=\"info-label\">DOC:</span><span class=\"info-value\">{$driverDoc}</span></div>";
                }
            }

            $sunatInfo = '';
            if ($ticket !== '') {
                $sunatInfo .= "<div style=\"font-size:7px;color:#555;word-break:break-all;margin:0.3mm 0\">Ticket: {$ticket}</div>";
            }
            if ($cdrCode !== '') {
                $sunatInfo .= "<div style=\"font-size:7px;color:#555\">CDR: {$cdrCode}</div>";
            }
            if ($sunatLink !== '') {
                $sunatInfo .= "<div style=\"font-size:7px;color:#555;word-break:break-all;margin:0.3mm 0\">SUNAT: {$sunatLink}</div>";
                if ($sunatQr !== '') {
                    $sunatInfo .= "<div style=\"text-align:center;margin-top:1mm\"><img src=\"{$sunatQr}\" alt=\"QR SUNAT\" style=\"width:26mm;height:26mm;border:1px solid #111;background:#fff\" /></div>";
                }
            }

            return <<<HTML
<!doctype html><html>
<head>
<meta charset="utf-8">
<title>GRE {$identifier}</title>
<style>
  @media print { @page { size: 80mm auto; margin: 0; } .no-print { display: none !important; } body { margin: 0; padding: 0; } }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Courier New', Courier, monospace; color: #000; font-size: 9px; line-height: 1.35; background: #fff; width: 80mm; margin: 0 auto; }
  .print-bar { background: linear-gradient(120deg, #0f172a 0%, #1e3a8a 100%); color: #fff; padding: 6px 8px; font-family: sans-serif; font-size: 12px; display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; border-radius: 8px; white-space: nowrap; }
  .print-bar .print-bar-title { font-weight: 700; letter-spacing: 0.2px; font-size: 11px; }
  .print-bar button { background: #fff; color: #0f172a; border: 1px solid #cbd5e1; padding: 4px 10px; font-size: 10px; font-weight: 700; border-radius: 8px; cursor: pointer; }
  .sheet { width: 80mm; margin: 0; padding: 2mm 3mm; }
  .divider { border-top: 1px dashed #000; margin: 2mm 0; opacity: 0.6; }
  .c { text-align: center; }
  .b { font-weight: bold; }
  .title { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 1mm; }
  .docno { font-size: 11px; font-weight: 700; margin-bottom: 0.5mm; letter-spacing: 1px; }
  .section-title { font-weight: 700; text-transform: uppercase; font-size: 8px; margin-bottom: 1mm; border-bottom: 1px solid #000; padding-bottom: 0.5mm; }
  .info-row { display: flex; justify-content: space-between; font-size: 8px; margin: 0.3mm 0; }
  .info-label { font-weight: 600; flex: 0 0 auto; margin-right: 2mm; }
  .info-value { flex: 1; text-align: right; }
  .items { margin-bottom: 2mm; border-top: 1px solid #000; border-bottom: 1px solid #000; padding: 1mm 0; }
  table { width: 100%; border-collapse: collapse; }
  td { padding: 0.5mm 0; font-size: 8px; }
  .footer { text-align: center; font-size: 7px; color: #555; margin-top: 2mm; border-top: 1px dashed #000; padding-top: 1mm; line-height: 1.2; }
</style>
</head>
<body>
<div class="print-bar no-print">
  <span class="print-bar-title">GRE &mdash; Ticket 80mm</span>
  <button onclick="window.print()">Imprimir</button>
</div>
<div class="sheet">
  <div class="c b" style="font-size:11px">{$companyName}</div>
  <div class="c" style="font-size:8px;color:#555">R.U.C. {$companyRuc}</div>
  <div class="c" style="font-size:8px;color:#555">{$companyAddress}</div>
  <div class="divider"></div>
  <div class="c title">GUIA DE REMISION ELECTRONICA</div>
  <div class="c docno">{$identifier}</div>
  <div class="c" style="font-size:8px;color:#555">Tipo: {$guideType}</div>
  <div class="divider"></div>
  <div class="section-title">Destinatario</div>
  <div class="info-row"><span class="info-label">NOMBRE:</span><span class="info-value">{$destName}</span></div>
  <div class="info-row"><span class="info-label">DOC:</span><span class="info-value">{$destDoc}</span></div>
  <div class="divider"></div>
  <div class="section-title">Traslado</div>
  <div class="info-row"><span class="info-label">EMISION:</span><span class="info-value">{$issueDate}</span></div>
  <div class="info-row"><span class="info-label">TRASLADO:</span><span class="info-value">{$transferDate}</span></div>
  <div class="info-row"><span class="info-label">MOTIVO:</span><span class="info-value">{$motivo}</span></div>
  <div class="info-row"><span class="info-label">MODO:</span><span class="info-value">{$modoLabel}</span></div>
  <div class="info-row"><span class="info-label">PARTIDA:</span><span class="info-value">{$partida}</span></div>
  <div class="info-row"><span class="info-label">LLEGADA:</span><span class="info-value">{$llegada}</span></div>
  {$transportInfo}
  <div class="divider"></div>
  <div class="section-title">Mercaderia</div>
  <div class="items"><table><tbody>{$itemRows}</tbody></table></div>
  <div class="info-row"><span class="info-label">PESO:</span><span class="info-value">{$weightKg} kg</span></div>
  <div class="info-row"><span class="info-label">BULTOS:</span><span class="info-value">{$packages}</span></div>
  <div class="footer">
    <div>Estado: {$status}</div>
    {$sunatInfo}
    <div class="divider" style="margin:1mm 0"></div>
    <div>Guia de Remision {$guideType}</div>
  </div>
</div>
</body></html>
HTML;
        }

        // ── A4 format ────────────────────────────────────────────────────────────
        $itemRowsA4 = '';
        foreach ($items as $idx => $item) {
            $desc = htmlspecialchars((string) ($item['description'] ?? ''), ENT_QUOTES, 'UTF-8');
            $qty  = number_format((float) ($item['qty'] ?? 0), 3);
            $code = htmlspecialchars((string) ($item['code'] ?? ''), ENT_QUOTES, 'UTF-8');
            $unit = htmlspecialchars((string) ($item['unit'] ?? ''), ENT_QUOTES, 'UTF-8');
            $no   = $idx + 1;
            $itemRowsA4 .= "<tr><td class=\"ta-c\">{$no}</td><td class=\"ta-c\">{$code}</td><td>{$desc}</td><td class=\"ta-r\">{$qty}</td><td class=\"ta-c\">{$unit}</td></tr>";
        }
        if ($itemRowsA4 === '') {
            $itemRowsA4 = "<tr><td colspan=\"5\" class=\"ta-c\">Sin items</td></tr>";
        }

        $transportRowsA4 = '';
        if ($modoLabel === 'Publico') {
            if ($transporterName !== '') {
                $transportRowsA4 = "<tr><td class=\"label\">Transportista:</td><td class=\"value\">{$transporterName} &mdash; {$transporterDoc}</td></tr>";
            }
        } else {
            if ($vehiclePlaca !== '') {
                $transportRowsA4 .= "<tr><td class=\"label\">Vehiculo (placa):</td><td class=\"value\">{$vehiclePlaca}</td></tr>";
            }
            if ($driverName !== '') {
                $transportRowsA4 .= "<tr><td class=\"label\">Conductor:</td><td class=\"value\">{$driverName} &mdash; {$driverDoc}</td></tr>";
            }
        }

        $sunatRowsA4 = "<tr><td class=\"label\">Estado:</td><td class=\"value\">{$status}</td></tr>";
        if ($ticket !== '') {
            $sunatRowsA4 .= "<tr><td class=\"label\">Ticket SUNAT:</td><td class=\"value\"><code style=\"font-size:10px;word-break:break-all\">{$ticket}</code></td></tr>";
        }
        if ($cdrCode !== '') {
            $sunatRowsA4 .= "<tr><td class=\"label\">CDR:</td><td class=\"value\">{$cdrCode} {$cdrDesc}</td></tr>";
        }
        if ($sunatLink !== '') {
            $sunatRowsA4 .= "<tr><td class=\"label\">Consulta SUNAT:</td><td class=\"value\" style=\"word-break:break-all\">{$sunatLink}</td></tr>";
            if ($sunatQr !== '') {
                $sunatRowsA4 .= "<tr><td class=\"label\">QR SUNAT:</td><td class=\"value\"><img src=\"{$sunatQr}\" alt=\"QR SUNAT\" style=\"width:120px;height:120px;border:1px solid #d1d5db;border-radius:6px;background:#fff\" /></td></tr>";
            }
        }

        return <<<HTML
<!doctype html><html>
<head>
<meta charset="utf-8">
<title>GRE {$identifier}</title>
<style>
  @page { size: A4 portrait; margin: 9mm; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: "Segoe UI", Tahoma, sans-serif; color: #1f2937; }
  .print-bar { background: linear-gradient(120deg, #0f172a 0%, #1e3a8a 100%); color: #fff; padding: 10px 14px; display: flex; align-items: center; justify-content: space-between; font-size: 13px; }
  .print-bar button { background: #ffffff; color: #0f172a; border: 1px solid #cbd5e1; padding: 7px 12px; font-size: 12px; font-weight: 700; border-radius: 8px; cursor: pointer; margin-left: 8px; }
  .sheet { width: 100%; border: 1.5px solid #1f2937; min-height: 277mm; padding: 8mm; }
  .head { display: grid; grid-template-columns: 1.1fr 1fr; gap: 10px; align-items: stretch; }
  .brand { border: 1px solid #9ca3af; border-radius: 8px; padding: 10px; }
  .brand h1 { margin: 0; font-size: 22px; letter-spacing: 0.6px; }
  .brand p { margin: 2px 0; font-size: 11px; color: #4b5563; }
  .voucher { border: 1px solid #9ca3af; border-radius: 8px; padding: 10px; text-align: center; }
  .voucher .ruc { font-size: 26px; font-weight: 700; letter-spacing: 1px; }
  .voucher .v-title { font-size: 13px; margin-top: 6px; letter-spacing: 1.4px; text-transform: uppercase; font-weight: 700; }
  .voucher .docno { margin-top: 8px; font-size: 20px; font-weight: 700; }
  .voucher .sub { font-size: 11px; color: #6b7280; margin-top: 4px; }
  .party { margin-top: 10px; border: 1px solid #9ca3af; border-radius: 8px; padding: 8px 10px; font-size: 12px; display: grid; grid-template-columns: 1.6fr 1fr; gap: 12px; }
  .kv { margin: 2px 0; }
  .kv b { display: inline-block; min-width: 118px; }
  .section-label { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em; color: #6b7280; border-bottom: 1px solid #e5e7eb; padding-bottom: 3px; margin: 10px 0 6px; }
  .table-wrap { margin-top: 10px; }
  table.meta { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
  table.meta td { padding: 4px 8px; font-size: 11px; vertical-align: top; }
  table.meta .label { width: 170px; font-weight: 600; color: #374151; text-align: right; }
  table.items { width: 100%; border-collapse: collapse; }
  table.items th { background: #60a5fa; color: #0f172a; font-size: 11px; text-transform: uppercase; letter-spacing: 0.2px; padding: 5px 6px; border-bottom: 1px solid #1f2937; }
  table.items td { border-bottom: 1px solid #d1d5db; font-size: 11px; padding: 5px 6px; vertical-align: top; }
  .ta-r { text-align: right; }
  .ta-c { text-align: center; }
  .obs { margin-top: 12px; border-top: 1px solid #9ca3af; padding-top: 6px; font-size: 11px; color: #4b5563; }
  @media print { .no-print { display: none !important; } }
</style>
</head>
<body>
<div class="print-bar no-print">
  <span>Vista Previa &mdash; Guia de Remision A4</span>
  <button onclick="window.print()">Imprimir</button>
</div>
<section class="sheet">
  <section class="head">
    <article class="brand">
      <h1>{$companyName}</h1>
      <p>R.U.C.: {$companyRuc}</p>
      <p>{$companyTrade}</p>
      <p>{$companyAddress}</p>
      <p>Fecha emision: {$issueDate}</p>
    </article>
    <article class="voucher">
      <div class="ruc">R.U.C.: {$companyRuc}</div>
      <div class="v-title">Guia de Remision Electronica</div>
      <div class="docno">{$identifier}</div>
      <div class="sub">Tipo: {$guideType} | Modo: {$modoLabel}</div>
      <div class="sub">Motivo: {$motivo}</div>
    </article>
  </section>

  <section class="party">
    <article>
      <p class="kv"><b>Destinatario:</b> {$destName}</p>
      <p class="kv"><b>RUC / DNI:</b> {$destDoc}</p>
      <p class="kv"><b>Direccion:</b> {$destAddress}</p>
    </article>
    <article>
      <p class="kv"><b>Fecha Emision:</b> {$issueDate}</p>
      <p class="kv"><b>Fecha Traslado:</b> {$transferDate}</p>
      <p class="kv"><b>Motivo:</b> {$motivo}</p>
    </article>
  </section>

  <div class="section-label">Traslado</div>
  <table class="meta">
    <tr><td class="label">Punto partida:</td><td class="value">{$partida}</td></tr>
    <tr><td class="label">Punto llegada:</td><td class="value">{$llegada}</td></tr>
    <tr><td class="label">Peso bruto:</td><td class="value">{$weightKg} kg</td></tr>
    <tr><td class="label">N&deg; bultos:</td><td class="value">{$packages}</td></tr>
    {$transportRowsA4}
  </table>

  <div class="section-label">Mercaderia</div>
  <div class="table-wrap">
    <table class="items">
      <thead>
        <tr>
          <th style="width:36px">#</th>
          <th style="width:80px">Codigo</th>
          <th>Descripcion</th>
          <th style="width:80px;text-align:right">Cantidad</th>
          <th style="width:70px;text-align:center">Unidad</th>
        </tr>
      </thead>
      <tbody>{$itemRowsA4}</tbody>
    </table>
  </div>

  <div class="section-label">SUNAT</div>
  <table class="meta">
    {$sunatRowsA4}
  </table>

  <section class="obs">
    Documento generado electronicamente. Guia de Remision {$guideType}.
  </section>
</section>
</body></html>
HTML;
    }

    private function buildGrePayload(int $companyId, object $row, array $config): array
    {
        $items = json_decode((string) ($row->items ?? '[]'), true);
        $items = is_array($items) ? $items : [];

        $destinatario = json_decode((string) ($row->destinatario ?? '{}'), true);
        $destinatario = is_array($destinatario) ? $destinatario : [];

        $transporter = json_decode((string) ($row->transporter ?? '{}'), true);
        $transporter = is_array($transporter) ? $transporter : [];

        $vehicle = json_decode((string) ($row->vehicle ?? '{}'), true);
        $vehicle = is_array($vehicle) ? $vehicle : [];

        $driver = json_decode((string) ($row->driver ?? '{}'), true);
        $driver = is_array($driver) ? $driver : [];

        $modalidad = (string) ($row->transport_mode_code ?? '02');

        $cabecera = [
            'tipo_documento' => '09',
            'guia_serie' => (string) ($row->series ?? ''),
            'guia_numero' => str_pad((string) ($row->number ?? 0), 8, '0', STR_PAD_LEFT),
            'fecha_emision' => (string) ($row->issue_date ?? ''),
            'fecha_traslado' => (string) (($row->transfer_date ?? null) ?: ($row->issue_date ?? '')),
            'motivo_codigo' => (string) ($row->motivo_traslado ?? '01'),
            'motivo_descripcion' => $this->resolveTransferReasonDescription((string) ($row->motivo_traslado ?? '01')),
            'modalidad_codigo' => $modalidad,
            'peso_total' => round((float) ($row->weight_kg ?? 0), 3),
            'numero_bultos' => (int) ($row->packages_count ?? 1),
            'ubigeo_partida' => (string) ($row->partida_ubigeo ?? ''),
            'partida_direccion' => (string) ($row->punto_partida ?? ''),
            'ubigeo_llegada' => (string) ($row->llegada_ubigeo ?? ''),
            'llegada_direccion' => (string) ($row->punto_llegada ?? ''),
            'destinatario_codigo' => (string) ($destinatario['doc_type'] ?? '6'),
            'destinatario_ruc' => (string) ($destinatario['doc_number'] ?? ''),
            'destinatario_razon_social' => (string) ($destinatario['name'] ?? ''),
        ];

        if ($modalidad === '02') {
            $cabecera['vehiculo_placa'] = (string) ($vehicle['placa'] ?? '');
            $cabecera['conductor_codigo'] = (string) ($driver['doc_type'] ?? '1');
            $cabecera['conductor_ruc'] = (string) ($driver['doc_number'] ?? '');
            $cabecera['conductor_licencia'] = (string) ($driver['license'] ?? ($driver['licencia'] ?? ''));
            $cabecera['conductor_razon_social'] = (string) ($driver['name'] ?? '');
        } else {
            $cabecera['transporte_codigo'] = (string) ($transporter['doc_type'] ?? '6');
            $cabecera['transporte_ruc'] = (string) ($transporter['doc_number'] ?? '');
            $cabecera['transporte_razon_social'] = (string) ($transporter['name'] ?? '');
            $nroMtc = trim((string) ($transporter['nro_mtc'] ?? ($transporter['mtc'] ?? '')));
            if ($nroMtc !== '') {
                $cabecera['nro_mtc'] = $nroMtc;
            }
        }

        $detalle = [];
        foreach ($items as $item) {
            $detalle[] = [
                'codigo' => (string) ($item['code'] ?? ''),
                'descripcion' => (string) ($item['description'] ?? ''),
                'cantidad' => (float) ($item['qty'] ?? 0),
                'unidad' => (string) ($item['unit'] ?? 'NIU'),
            ];
        }

        return [
            'empresa' => $this->buildCompanyAuthBlock($companyId, $config),
            'cabecera' => $cabecera,
            'detalle' => $detalle,
        ];
    }

    private function buildCompanyAuthBlock(int $companyId, array $config): array
    {
        $company = DB::table('core.companies as c')
            ->leftJoin('core.company_settings as cs', 'cs.company_id', '=', 'c.id')
            ->where('c.id', $companyId)
            ->select(
                'c.tax_id',
                'c.legal_name',
                'c.trade_name',
                'c.address',
                'cs.address as settings_address',
                'cs.phone as settings_phone',
                'cs.email as settings_email',
                'cs.extra_data as settings_extra_data'
            )
            ->first();

        $extraData = [];
        if (is_object($company) && isset($company->settings_extra_data)) {
            $decoded = json_decode((string) ($company->settings_extra_data ?? '{}'), true);
            if (is_array($decoded)) {
                $extraData = $decoded;
            }
        }

        $direccion = trim((string) (($company->settings_address ?? '') ?: ($company->address ?? '')));

        return [
            'ruc' => (string) ($company->tax_id ?? ''),
            'user' => (string) (($config['sunat_secondary_user'] ?? '') ?: ($config['sol_user'] ?? '')),
            'pass' => (string) (($config['sunat_secondary_pass'] ?? '') ?: ($config['sol_pass'] ?? '')),
            'razon_social' => (string) ($company->legal_name ?? ''),
            'nombre_comercial' => (string) ($company->trade_name ?? ''),
            'direccion' => $direccion,
            'urbanizacion' => trim((string) ($extraData['urbanizacion'] ?? '')),
            'ubigeo' => trim((string) ($extraData['ubigeo'] ?? '')),
            'departamento' => trim((string) ($extraData['departamento'] ?? '')),
            'provincia' => trim((string) ($extraData['provincia'] ?? '')),
            'distrito' => trim((string) ($extraData['distrito'] ?? '')),
        ];
    }

    private function resolveTransferReasonDescription(string $code): string
    {
        $code = trim($code);
        if ($code === '') {
            return '';
        }

        $dbValue = DB::table('sales.gre_transfer_reasons')
            ->where('code', $code)
            ->value('name');

        if (is_string($dbValue) && trim($dbValue) !== '') {
            return trim($dbValue);
        }

        $fallback = [
            '01' => 'Venta',
            '02' => 'Compra',
            '04' => 'Traslado entre establecimientos de la misma empresa',
            '08' => 'Importacion',
            '09' => 'Exportacion',
            '13' => 'Otros',
            '14' => 'Venta sujeta a confirmacion del comprador',
        ];

        return $fallback[$code] ?? $code;
    }

    private function resolveBridgeEndpoint(string $rawBaseUrl, string $methodName): string
    {
        $url = trim($rawBaseUrl);
        $methodName = trim($methodName);

        if ($url === '' || $methodName === '') {
            return '';
        }

        $normalized = rtrim($url, '/');

        if (preg_match('#^(.*?/index\\.php/sunat/)([^/?\\#]+)(.*)$#i', $normalized, $m) === 1) {
            return $m[1] . $methodName . ($m[3] ?? '');
        }
        if (preg_match('#^(.*?/sunat/)([^/?\\#]+)(.*)$#i', $normalized, $m) === 1) {
            return $m[1] . $methodName . ($m[3] ?? '');
        }
        if (preg_match('#/index\\.php/sunat$#i', $normalized) === 1) {
            return $normalized . '/' . $methodName;
        }
        if (preg_match('#/sunat$#i', $normalized) === 1) {
            return $normalized . '/' . $methodName;
        }
        if (preg_match('#/index\\.php$#i', $normalized) === 1) {
            return $normalized . '/Sunat/' . $methodName;
        }

        return $normalized . '/index.php/Sunat/' . $methodName;
    }

    private function nextNumber(int $companyId, string $series): int
    {
        return (int) DB::table('sales.gre_guides')
                ->where('company_id', $companyId)
                ->where('series', $series)
                ->max('number') + 1;
    }

    private function normalizeGuideRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'company_id' => (int) $row->company_id,
            'branch_id' => $row->branch_id !== null ? (int) $row->branch_id : null,
            'guide_type' => (string) $row->guide_type,
            'issue_date' => (string) $row->issue_date,
            'transfer_date' => $row->transfer_date !== null ? (string) $row->transfer_date : null,
            'series' => (string) $row->series,
            'number' => (int) $row->number,
            'identifier' => (string) $row->identifier,
            'status' => (string) $row->status,
            'notes' => $row->notes,
            'motivo_traslado' => (string) $row->motivo_traslado,
            'transport_mode_code' => (string) ($row->transport_mode_code ?? '02'),
            'weight_kg' => (float) $row->weight_kg,
            'packages_count' => (int) $row->packages_count,
            'partida_ubigeo' => $row->partida_ubigeo,
            'punto_partida' => (string) $row->punto_partida,
            'llegada_ubigeo' => $row->llegada_ubigeo,
            'punto_llegada' => (string) $row->punto_llegada,
            'related_document_id' => $row->related_document_id !== null ? (int) $row->related_document_id : null,
            'transporter' => json_decode((string) ($row->transporter ?? '{}'), true),
            'vehicle' => json_decode((string) ($row->vehicle ?? '{}'), true),
            'driver' => json_decode((string) ($row->driver ?? '{}'), true),
            'destinatario' => json_decode((string) ($row->destinatario ?? '{}'), true),
            'items' => json_decode((string) ($row->items ?? '[]'), true),
            'bridge_method' => $row->bridge_method,
            'bridge_endpoint' => $row->bridge_endpoint,
            'bridge_http_code' => $row->bridge_http_code !== null ? (int) $row->bridge_http_code : null,
            'sunat_ticket' => $row->sunat_ticket,
            'sunat_cdr_code' => $row->sunat_cdr_code,
            'sunat_cdr_desc' => $row->sunat_cdr_desc,
            'raw_response' => is_string($row->raw_response) ? json_decode($row->raw_response, true) : $row->raw_response,
            'sent_at' => $row->sent_at,
            'cancelled_at' => $row->cancelled_at,
            'cancelled_reason' => $row->cancelled_reason,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    private function tableExists(string $qualifiedName): bool
    {
        if (strpos($qualifiedName, '.') === false) {
            return Schema::hasTable($qualifiedName);
        }

        [$schema, $table] = explode('.', $qualifiedName, 2);

        return DB::table('information_schema.tables')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->exists();
    }

    private function detectUbigeoSource(): ?array
    {
        $candidates = DB::table('information_schema.columns')
            ->select('table_schema', 'table_name', DB::raw('array_agg(lower(column_name)) as cols'))
            ->whereIn('table_schema', ['core', 'public', 'sunat'])
            ->where('table_name', 'ILIKE', '%ubigeo%')
            ->groupBy('table_schema', 'table_name')
            ->get();

        foreach ($candidates as $candidate) {
            $cols = is_string($candidate->cols)
                ? array_map(
                    static fn($value) => strtolower(trim(str_replace('"', '', $value))),
                    explode(',', trim($candidate->cols, '{}'))
                )
                : [];

            $code = $this->firstColumn($cols, ['code', 'codigo', 'ubigeo']);
            $district = $this->firstColumn($cols, ['district', 'distrito']);
            $province = $this->firstColumn($cols, ['province', 'provincia']);
            $department = $this->firstColumn($cols, ['department', 'departamento']);

            if ($code && $district && $province && $department) {
                return [
                    'table' => $candidate->table_schema . '.' . $candidate->table_name,
                    'code' => $code,
                    'district' => $district,
                    'province' => $province,
                    'department' => $department,
                ];
            }
        }

        return null;
    }

    private function firstColumn(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    private function buildEffectivePayloadForValidation(object $row, array $incoming): array
    {
        $current = [
            'guide_type' => (string) ($row->guide_type ?? 'REMITENTE'),
            'motivo_traslado' => (string) ($row->motivo_traslado ?? '01'),
            'transport_mode_code' => (string) ($row->transport_mode_code ?? '02'),
            'weight_kg' => (float) ($row->weight_kg ?? 0),
            'packages_count' => (int) ($row->packages_count ?? 0),
            'partida_ubigeo' => (string) ($row->partida_ubigeo ?? ''),
            'llegada_ubigeo' => (string) ($row->llegada_ubigeo ?? ''),
            'punto_partida' => (string) ($row->punto_partida ?? ''),
            'punto_llegada' => (string) ($row->punto_llegada ?? ''),
            'related_document_id' => $row->related_document_id ?? null,
            'destinatario' => json_decode((string) ($row->destinatario ?? '{}'), true) ?: [],
            'transporter' => json_decode((string) ($row->transporter ?? '{}'), true) ?: [],
            'vehicle' => json_decode((string) ($row->vehicle ?? '{}'), true) ?: [],
            'driver' => json_decode((string) ($row->driver ?? '{}'), true) ?: [],
            'items' => json_decode((string) ($row->items ?? '[]'), true) ?: [],
        ];

        return array_replace_recursive($current, $incoming);
    }

    private function enforceBusinessRules(array $payload): void
    {
        $guideType = strtoupper(trim((string) ($payload['guide_type'] ?? '')));
        if (!in_array($guideType, ['REMITENTE', 'TRANSPORTISTA'], true)) {
            throw new TaxBridgeException('Tipo de guia invalido', 422);
        }

        $motivo = trim((string) ($payload['motivo_traslado'] ?? ''));
        if ($motivo === '') {
            throw new TaxBridgeException('Motivo de traslado es obligatorio', 422);
        }

        $transportMode = trim((string) ($payload['transport_mode_code'] ?? ''));
        if (!in_array($transportMode, ['01', '02'], true)) {
            throw new TaxBridgeException('Tipo de transporte invalido (01 publico / 02 privado)', 422);
        }

        $partidaUbigeo = trim((string) ($payload['partida_ubigeo'] ?? ''));
        $llegadaUbigeo = trim((string) ($payload['llegada_ubigeo'] ?? ''));
        if ($partidaUbigeo === '' || preg_match('/^\d{6}$/', $partidaUbigeo) !== 1) {
            throw new TaxBridgeException('Ubigeo de partida invalido (debe tener 6 digitos)', 422);
        }
        if ($llegadaUbigeo === '' || preg_match('/^\d{6}$/', $llegadaUbigeo) !== 1) {
            throw new TaxBridgeException('Ubigeo de llegada invalido (debe tener 6 digitos)', 422);
        }

        if (trim((string) ($payload['punto_partida'] ?? '')) === '' || trim((string) ($payload['punto_llegada'] ?? '')) === '') {
            throw new TaxBridgeException('Puntos de partida y llegada son obligatorios', 422);
        }

        $weightRaw = $payload['weight_kg'] ?? 0;
        $weight = (float) $weightRaw;
        $packages = (int) ($payload['packages_count'] ?? 0);
        if ($weight <= 0) {
            throw new TaxBridgeException('Peso bruto debe ser mayor a 0', 422);
        }
        if (!is_numeric((string) $weightRaw) || abs($weight - round($weight, 3)) > 0.0000001) {
            throw new TaxBridgeException('Peso bruto debe ser numerico y tener maximo 3 decimales', 422);
        }
        if ($packages <= 0) {
            throw new TaxBridgeException('Numero de bultos debe ser mayor a 0', 422);
        }

        $dest = is_array($payload['destinatario'] ?? null) ? $payload['destinatario'] : [];
        if (trim((string) ($dest['doc_type'] ?? '')) === '' || trim((string) ($dest['doc_number'] ?? '')) === '' || trim((string) ($dest['name'] ?? '')) === '') {
            throw new TaxBridgeException('Destinatario requiere tipo de documento, numero y razon social/nombre', 422);
        }

        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        if (count($items) === 0) {
            throw new TaxBridgeException('Debe incluir al menos un item en la guia', 422);
        }

        foreach ($items as $index => $item) {
            $description = trim((string) ($item['description'] ?? ''));
            $qty = (float) ($item['qty'] ?? 0);
            if ($description === '' || $qty <= 0) {
                throw new TaxBridgeException('Item ' . ($index + 1) . ' invalido: descripcion y cantidad son obligatorias', 422);
            }
        }

        if (in_array($motivo, ['01', '02', '14'], true)) {
            $relatedDocumentId = (int) ($payload['related_document_id'] ?? 0);
            if ($relatedDocumentId <= 0) {
                throw new TaxBridgeException('Para este motivo se requiere comprobante relacionado', 422);
            }
        }

        if ($transportMode === '01') {
            $transporter = is_array($payload['transporter'] ?? null) ? $payload['transporter'] : [];
            if (trim((string) ($transporter['doc_type'] ?? '')) === '' || trim((string) ($transporter['doc_number'] ?? '')) === '' || trim((string) ($transporter['name'] ?? '')) === '') {
                throw new TaxBridgeException('Transporte publico requiere tipo y datos de transportista', 422);
            }
        }

        if ($transportMode === '02') {
            $vehicle = is_array($payload['vehicle'] ?? null) ? $payload['vehicle'] : [];
            $driver = is_array($payload['driver'] ?? null) ? $payload['driver'] : [];
            $driverLicense = trim((string) ($driver['license'] ?? ($driver['licencia'] ?? '')));
            if (trim((string) ($vehicle['placa'] ?? '')) === '') {
                throw new TaxBridgeException('Transporte privado requiere placa de vehiculo', 422);
            }
            if (trim((string) ($driver['doc_type'] ?? '')) === '' || trim((string) ($driver['doc_number'] ?? '')) === '' || trim((string) ($driver['name'] ?? '')) === '') {
                throw new TaxBridgeException('Transporte privado requiere tipo y datos de conductor', 422);
            }
            if ($driverLicense === '') {
                throw new TaxBridgeException('Transporte privado requiere licencia de conductor', 422);
            }
        }
    }

    private function resolveFeatureResolutionForCompany(int $companyId, string $featureCode, bool $defaultEnabled = false): array
    {
        $normalizedFeatureCode = strtoupper(trim($featureCode));
        $cacheKey = $companyId . ':' . $normalizedFeatureCode . ':' . ($defaultEnabled ? '1' : '0');
        if (array_key_exists($cacheKey, $this->featureResolutionCache)) {
            return $this->featureResolutionCache[$cacheKey];
        }

        $companyRow = DB::table('appcfg.company_feature_toggles')
            ->where('company_id', $companyId)
            ->whereRaw('UPPER(feature_code) = ?', [$normalizedFeatureCode])
            ->first(['is_enabled']);

        $companyEnabled = $companyRow && $companyRow->is_enabled !== null ? (bool) $companyRow->is_enabled : null;
        $isEnabled = $companyEnabled !== null ? $companyEnabled : $defaultEnabled;

        $verticalPreference = $this->resolveVerticalFeaturePreference($companyId, $normalizedFeatureCode);
        if ($verticalPreference['resolved'] && $verticalPreference['is_enabled'] !== null) {
            $isEnabled = (bool) $verticalPreference['is_enabled'];
        }

        $resolved = [
            'is_enabled' => (bool) $isEnabled,
            'vertical_source' => $verticalPreference['source'],
        ];

        $this->featureResolutionCache[$cacheKey] = $resolved;
        return $resolved;
    }

    private function resolveVerticalFeaturePreference(int $companyId, string $featureCode): array
    {
        $normalizedFeatureCode = strtoupper(trim($featureCode));
        $cacheKey = $companyId . ':' . $normalizedFeatureCode;
        if (array_key_exists($cacheKey, $this->verticalFeaturePreferenceCache)) {
            return $this->verticalFeaturePreferenceCache[$cacheKey];
        }

        $default = [
            'resolved' => false,
            'is_enabled' => null,
            'source' => null,
        ];

        if (!$this->tableExists('appcfg.verticals')
            || !$this->tableExists('appcfg.company_verticals')
            || !$this->tableExists('appcfg.vertical_feature_templates')
            || !$this->tableExists('appcfg.company_vertical_feature_overrides')) {
            $this->verticalFeaturePreferenceCache[$cacheKey] = $default;
            return $default;
        }

        $activeVertical = $this->resolveActiveCompanyVertical($companyId);
        if ($activeVertical === null) {
            $this->verticalFeaturePreferenceCache[$cacheKey] = $default;
            return $default;
        }

        $override = DB::table('appcfg.company_vertical_feature_overrides')
            ->where('company_id', $companyId)
            ->where('vertical_id', (int) $activeVertical['id'])
            ->whereRaw('UPPER(feature_code) = ?', [$normalizedFeatureCode])
            ->first(['is_enabled']);

        if ($override && $override->is_enabled !== null) {
            $resolved = [
                'resolved' => true,
                'is_enabled' => (bool) $override->is_enabled,
                'source' => 'COMPANY_VERTICAL_OVERRIDE',
            ];
            $this->verticalFeaturePreferenceCache[$cacheKey] = $resolved;
            return $resolved;
        }

        $template = DB::table('appcfg.vertical_feature_templates')
            ->where('vertical_id', (int) $activeVertical['id'])
            ->whereRaw('UPPER(feature_code) = ?', [$normalizedFeatureCode])
            ->first(['is_enabled']);

        if ($template && $template->is_enabled !== null) {
            $resolved = [
                'resolved' => true,
                'is_enabled' => (bool) $template->is_enabled,
                'source' => 'VERTICAL_TEMPLATE',
            ];
            $this->verticalFeaturePreferenceCache[$cacheKey] = $resolved;
            return $resolved;
        }

        $this->verticalFeaturePreferenceCache[$cacheKey] = $default;
        return $default;
    }

    private function resolveActiveCompanyVertical(int $companyId): ?array
    {
        if (array_key_exists($companyId, $this->activeVerticalCache)) {
            return $this->activeVerticalCache[$companyId];
        }

        if (!$this->tableExists('appcfg.verticals') || !$this->tableExists('appcfg.company_verticals')) {
            $this->activeVerticalCache[$companyId] = null;
            return null;
        }

        $row = DB::table('appcfg.company_verticals as cv')
            ->join('appcfg.verticals as v', 'v.id', '=', 'cv.vertical_id')
            ->where('cv.company_id', $companyId)
            ->where('cv.status', 1)
            ->where('v.status', 1)
            ->where('cv.is_primary', true)
            ->select('v.id', 'v.code', 'v.name')
            ->first();

        if (!$row) {
            $this->activeVerticalCache[$companyId] = null;
            return null;
        }

        $resolved = [
            'id' => (int) $row->id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
        ];

        $this->activeVerticalCache[$companyId] = $resolved;
        return $resolved;
    }

    private function resolveGreSunatConstancyLink(array $rawResponse, string $cdrDesc): string
    {
        $link = $this->extractBridgeField($rawResponse, ['link', 'enlace', 'reference', 'cdr_link', 'cdrReference', 'url', 'consulta_url']) ?? '';
        if ($link !== '' && preg_match('/^https?:\/\//i', $link) === 1) {
            return trim($link);
        }

        if (preg_match('/https?:\/\/[^\s<>"]+/i', $cdrDesc, $match) === 1) {
            return trim((string) $match[0]);
        }

        return '';
    }
}
