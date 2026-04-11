<?php

namespace App\Services\Restaurant;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class RestaurantComandaGateway
{
    public function list(
        int $companyId,
        ?int $branchId,
        string $status,
        string $search,
        int $page,
        int $perPage,
        ?string $authToken = null
    ): array {
        if ($this->isRemoteMode()) {
            return $this->listRemote($companyId, $branchId, $status, $search, $page, $perPage, $authToken);
        }

        return $this->listEmbedded($companyId, $branchId, $status, $search, $page, $perPage);
    }

    public function updateStatus(
        int $companyId,
        int $id,
        string $status,
        ?string $tableLabel,
        ?string $authToken = null
    ): array {
        if ($this->isRemoteMode()) {
            return $this->updateStatusRemote($companyId, $id, $status, $tableLabel, $authToken);
        }

        return $this->updateStatusEmbedded($companyId, $id, $status, $tableLabel);
    }

    public function listTables(
        int $companyId,
        ?int $branchId,
        string $status,
        string $search,
        ?string $authToken = null
    ): array {
        if ($this->isRemoteMode()) {
            return $this->listTablesRemote($companyId, $branchId, $status, $search, $authToken);
        }

        return $this->listTablesEmbedded($companyId, $branchId, $status, $search);
    }

    public function createTable(
        int $companyId,
        int $branchId,
        string $code,
        string $name,
        int $capacity,
        ?string $authToken = null
    ): array {
        if ($this->isRemoteMode()) {
            return $this->createTableRemote($companyId, $branchId, $code, $name, $capacity, $authToken);
        }

        return $this->createTableEmbedded($companyId, $branchId, $code, $name, $capacity);
    }

    public function updateTable(
        int $companyId,
        int $id,
        ?string $name,
        ?int $capacity,
        ?string $status,
        ?string $authToken = null
    ): array {
        if ($this->isRemoteMode()) {
            return $this->updateTableRemote($companyId, $id, $name, $capacity, $status, $authToken);
        }

        return $this->updateTableEmbedded($companyId, $id, $name, $capacity, $status);
    }

    private function isRemoteMode(): bool
    {
        return strtolower((string) config('services.restaurant.mode', 'embedded')) === 'remote';
    }

    private function remoteBaseUrl(): string
    {
        return rtrim((string) config('services.restaurant.base_url', ''), '/');
    }

    private function remoteHeaders(int $companyId, ?string $authToken): array
    {
        $headers = [
            'Accept' => 'application/json',
            'X-Company-Id' => (string) $companyId,
        ];

        $apiKey = trim((string) config('services.restaurant.api_key', ''));
        if ($apiKey !== '') {
            $headers['X-Service-Key'] = $apiKey;
        }

        if ($authToken && trim($authToken) !== '') {
            $headers['Authorization'] = 'Bearer ' . trim($authToken);
        }

        return $headers;
    }

    private function listRemote(
        int $companyId,
        ?int $branchId,
        string $status,
        string $search,
        int $page,
        int $perPage,
        ?string $authToken
    ): array {
        $baseUrl = $this->remoteBaseUrl();
        if ($baseUrl === '') {
            throw new \RuntimeException('Restaurant service base_url is not configured', 500);
        }

        $query = [
            'company_id' => $companyId,
            'page' => $page,
            'per_page' => $perPage,
        ];

        if ($branchId !== null) {
            $query['branch_id'] = $branchId;
        }
        if ($status !== '') {
            $query['status'] = $status;
        }
        if ($search !== '') {
            $query['search'] = $search;
        }

        $response = Http::timeout((int) config('services.restaurant.timeout_seconds', 8))
            ->withHeaders($this->remoteHeaders($companyId, $authToken))
            ->get($baseUrl . '/comandas', $query);

        if (!$response->successful()) {
            throw new \RuntimeException('Restaurant service unavailable', 502);
        }

        $payload = $response->json();
        return is_array($payload) ? $payload : [];
    }

    private function updateStatusRemote(
        int $companyId,
        int $id,
        string $status,
        ?string $tableLabel,
        ?string $authToken
    ): array {
        $baseUrl = $this->remoteBaseUrl();
        if ($baseUrl === '') {
            throw new \RuntimeException('Restaurant service base_url is not configured', 500);
        }

        $body = [
            'company_id' => $companyId,
            'status' => $status,
        ];
        if ($tableLabel !== null) {
            $body['table_label'] = $tableLabel;
        }

        $response = Http::timeout((int) config('services.restaurant.timeout_seconds', 8))
            ->withHeaders($this->remoteHeaders($companyId, $authToken))
            ->put($baseUrl . '/comandas/' . $id . '/status', $body);

        if (!$response->successful()) {
            throw new \RuntimeException('Restaurant service unavailable', 502);
        }

        $payload = $response->json();
        return is_array($payload) ? $payload : [];
    }

    private function listTablesRemote(
        int $companyId,
        ?int $branchId,
        string $status,
        string $search,
        ?string $authToken
    ): array {
        $baseUrl = $this->remoteBaseUrl();
        if ($baseUrl === '') {
            throw new \RuntimeException('Restaurant service base_url is not configured', 500);
        }

        $query = ['company_id' => $companyId];
        if ($branchId !== null) {
            $query['branch_id'] = $branchId;
        }
        if ($status !== '') {
            $query['status'] = $status;
        }
        if ($search !== '') {
            $query['search'] = $search;
        }

        $response = Http::timeout((int) config('services.restaurant.timeout_seconds', 8))
            ->withHeaders($this->remoteHeaders($companyId, $authToken))
            ->get($baseUrl . '/tables', $query);

        if (!$response->successful()) {
            throw new \RuntimeException('Restaurant service unavailable', 502);
        }

        $payload = $response->json();
        return is_array($payload) ? $payload : [];
    }

    private function createTableRemote(
        int $companyId,
        int $branchId,
        string $code,
        string $name,
        int $capacity,
        ?string $authToken
    ): array {
        $baseUrl = $this->remoteBaseUrl();
        if ($baseUrl === '') {
            throw new \RuntimeException('Restaurant service base_url is not configured', 500);
        }

        $response = Http::timeout((int) config('services.restaurant.timeout_seconds', 8))
            ->withHeaders($this->remoteHeaders($companyId, $authToken))
            ->post($baseUrl . '/tables', [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'code' => $code,
                'name' => $name,
                'capacity' => $capacity,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Restaurant service unavailable', 502);
        }

        $payload = $response->json();
        return is_array($payload) ? $payload : [];
    }

    private function updateTableRemote(
        int $companyId,
        int $id,
        ?string $name,
        ?int $capacity,
        ?string $status,
        ?string $authToken
    ): array {
        $baseUrl = $this->remoteBaseUrl();
        if ($baseUrl === '') {
            throw new \RuntimeException('Restaurant service base_url is not configured', 500);
        }

        $body = ['company_id' => $companyId];
        if ($name !== null) {
            $body['name'] = $name;
        }
        if ($capacity !== null) {
            $body['capacity'] = $capacity;
        }
        if ($status !== null) {
            $body['status'] = $status;
        }

        $response = Http::timeout((int) config('services.restaurant.timeout_seconds', 8))
            ->withHeaders($this->remoteHeaders($companyId, $authToken))
            ->put($baseUrl . '/tables/' . $id, $body);

        if (!$response->successful()) {
            throw new \RuntimeException('Restaurant service unavailable', 502);
        }

        $payload = $response->json();
        return is_array($payload) ? $payload : [];
    }

    private function listEmbedded(
        int $companyId,
        ?int $branchId,
        string $status,
        string $search,
        int $page,
        int $perPage
    ): array {
        $query = DB::table('sales.commercial_documents as d')
            ->leftJoin('sales.customers as c', 'c.id', '=', 'd.customer_id')
            ->where('d.company_id', $companyId)
            ->where('d.document_kind', 'SALES_ORDER')
            ->whereNotIn('d.status', ['VOID', 'CANCELED'])
            ->when($branchId !== null, function ($q) use ($branchId) {
                $q->where('d.branch_id', $branchId);
            })
            ->when($status !== '', function ($q) use ($status) {
                $q->whereRaw("UPPER(COALESCE(d.metadata->>'restaurant_order_status', 'PENDING')) = ?", [$status]);
            })
            ->when($search !== '', function ($q) use ($search) {
                $needle = '%' . mb_strtolower($search) . '%';
                $q->where(function ($nested) use ($needle) {
                    $nested->whereRaw("LOWER(COALESCE(d.series, '')) LIKE ?", [$needle])
                        ->orWhereRaw("CAST(d.number as text) LIKE ?", [$needle])
                        ->orWhereRaw("LOWER(COALESCE(c.legal_name, c.first_name || ' ' || c.last_name, '')) LIKE ?", [$needle])
                        ->orWhereRaw("LOWER(COALESCE(d.metadata->>'table_label', '')) LIKE ?", [$needle]);
                });
            });

        $total = (clone $query)->count();

        $rows = $query
            ->select([
                'd.id',
                'd.branch_id',
                'd.series',
                'd.number',
                'd.issue_at',
                'd.status',
                'd.total',
                DB::raw("COALESCE(c.legal_name, TRIM(COALESCE(c.first_name, '') || ' ' || COALESCE(c.last_name, ''))) as customer_name"),
                DB::raw("COALESCE(d.metadata->>'restaurant_order_status', 'PENDING') as kitchen_status"),
                DB::raw("COALESCE(d.metadata->>'table_label', '') as table_label"),
            ])
            ->orderByDesc('d.id')
            ->forPage($page, $perPage)
            ->get();

        return [
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) ceil(max(1, $total) / $perPage),
            ],
            'allowed_statuses' => ['PENDING', 'IN_PREP', 'READY', 'SERVED', 'CANCELLED'],
        ];
    }

    private function updateStatusEmbedded(int $companyId, int $id, string $status, ?string $tableLabel): array
    {
        $row = DB::table('sales.commercial_documents')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->where('document_kind', 'SALES_ORDER')
            ->first(['id', 'branch_id', 'metadata', 'status']);

        if (!$row) {
            throw new \RuntimeException('Comanda no encontrada', 404);
        }

        if (in_array((string) $row->status, ['VOID', 'CANCELED'], true)) {
            throw new \RuntimeException('No se puede actualizar una comanda anulada', 422);
        }

        $metadata = [];
        if (is_string($row->metadata) && trim($row->metadata) !== '') {
            $decoded = json_decode($row->metadata, true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }

        $metadata['restaurant_order_status'] = $status;
        if ($tableLabel !== null) {
            $value = trim($tableLabel);
            if ($value === '') {
                unset($metadata['table_label']);
            } else {
                $metadata['table_label'] = $value;
            }
        }

        DB::table('sales.commercial_documents')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->update([
                'metadata' => json_encode($metadata),
                'updated_at' => now(),
            ]);

        $this->syncEmbeddedTableStatus(
            $companyId,
            $row->branch_id !== null ? (int) $row->branch_id : null,
            (string) ($metadata['table_label'] ?? ''),
            (string) $metadata['restaurant_order_status']
        );

        return [
            'message' => 'Estado de comanda actualizado',
            'id' => $id,
            'status' => $metadata['restaurant_order_status'],
            'table_label' => $metadata['table_label'] ?? null,
        ];
    }

    private function syncEmbeddedTableStatus(int $companyId, ?int $branchId, string $tableLabel, string $kitchenStatus): void
    {
        $label = trim($tableLabel);
        if ($label === '' || !$this->restaurantTablesStorageExists()) {
            return;
        }

        $table = DB::table('restaurant.tables')
            ->where('company_id', $companyId)
            ->when($branchId !== null, function ($query) use ($branchId) {
                $query->where('branch_id', $branchId);
            })
            ->where(function ($query) use ($label) {
                $query->whereRaw('UPPER(name) = ?', [mb_strtoupper($label)])
                    ->orWhereRaw('UPPER(code) = ?', [mb_strtoupper($label)]);
            })
            ->first(['id', 'status']);

        if (!$table || strtoupper((string) $table->status) === 'DISABLED') {
            return;
        }

        $nextTableStatus = in_array(strtoupper(trim($kitchenStatus)), ['SERVED', 'CANCELLED'], true)
            ? 'AVAILABLE'
            : 'OCCUPIED';

        $currentStatus = strtoupper((string) $table->status);
        if ($currentStatus === 'RESERVED' && $nextTableStatus === 'OCCUPIED') {
            return;
        }

        DB::table('restaurant.tables')
            ->where('id', (int) $table->id)
            ->where('company_id', $companyId)
            ->update([
                'status' => $nextTableStatus,
                'updated_at' => now(),
            ]);
    }

    private function restaurantTablesStorageExists(): bool
    {
        return DB::table('information_schema.tables')
            ->where('table_schema', 'restaurant')
            ->where('table_name', 'tables')
            ->exists();
    }

    private function listTablesEmbedded(int $companyId, ?int $branchId, string $status, string $search): array
    {
        $this->ensureRestaurantTablesStorage();

        $query = DB::table('restaurant.tables')
            ->where('company_id', $companyId)
            ->when($branchId !== null, function ($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            })
            ->when($status !== '', function ($q) use ($status) {
                $q->whereRaw('UPPER(status) = ?', [strtoupper($status)]);
            })
            ->when($search !== '', function ($q) use ($search) {
                $needle = '%' . mb_strtolower($search) . '%';
                $q->where(function ($nested) use ($needle) {
                    $nested->whereRaw('LOWER(code) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(name) LIKE ?', [$needle]);
                });
            });

        $rows = $query
            ->select('id', 'company_id', 'branch_id', 'code', 'name', 'capacity', 'status', 'created_at', 'updated_at')
            ->orderBy('code')
            ->get();

        return [
            'data' => $rows,
            'allowed_statuses' => ['AVAILABLE', 'OCCUPIED', 'RESERVED', 'DISABLED'],
        ];
    }

    private function createTableEmbedded(int $companyId, int $branchId, string $code, string $name, int $capacity): array
    {
        $this->ensureRestaurantTablesStorage();

        $exists = DB::table('restaurant.tables')
            ->where('company_id', $companyId)
            ->whereRaw('UPPER(code) = ?', [strtoupper($code)])
            ->exists();

        if ($exists) {
            throw new \RuntimeException('Ya existe una mesa con ese codigo', 422);
        }

        $id = DB::table('restaurant.tables')->insertGetId([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'code' => strtoupper($code),
            'name' => $name,
            'capacity' => $capacity,
            'status' => 'AVAILABLE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'message' => 'Mesa creada',
            'id' => (int) $id,
        ];
    }

    private function updateTableEmbedded(int $companyId, int $id, ?string $name, ?int $capacity, ?string $status): array
    {
        $this->ensureRestaurantTablesStorage();

        $row = DB::table('restaurant.tables')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->first(['id']);

        if (!$row) {
            throw new \RuntimeException('Mesa no encontrada', 404);
        }

        $updates = ['updated_at' => now()];
        if ($name !== null) {
            $updates['name'] = trim($name);
        }
        if ($capacity !== null) {
            $updates['capacity'] = $capacity;
        }
        if ($status !== null) {
            $updates['status'] = strtoupper(trim($status));
        }

        DB::table('restaurant.tables')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->update($updates);

        return [
            'message' => 'Mesa actualizada',
            'id' => $id,
        ];
    }

    private function ensureRestaurantTablesStorage(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS restaurant');

        DB::statement(
            'CREATE TABLE IF NOT EXISTS restaurant.tables (
                id BIGSERIAL PRIMARY KEY,
                company_id BIGINT NOT NULL,
                branch_id BIGINT NOT NULL,
                code VARCHAR(40) NOT NULL,
                name VARCHAR(120) NOT NULL,
                capacity INTEGER NOT NULL DEFAULT 1,
                status VARCHAR(20) NOT NULL DEFAULT \'AVAILABLE\',
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                UNIQUE (company_id, code)
            )'
        );
    }
}
