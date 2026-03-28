<?php

namespace App\Support\Inventory;

use Illuminate\Support\Facades\DB;

class OutboxEngine
{
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_PROCESSING = 'PROCESSING';
    public const STATUS_PROCESSED = 'PROCESSED';
    public const STATUS_FAILED = 'FAILED';

    public static function ensureSchema(): void
    {
        DB::statement('CREATE TABLE IF NOT EXISTS inventory.outbox_events (
            id BIGSERIAL PRIMARY KEY,
            company_id BIGINT NOT NULL,
            aggregate_type VARCHAR(80) NOT NULL,
            aggregate_id VARCHAR(80) NOT NULL,
            event_type VARCHAR(120) NOT NULL,
            payload_json JSONB NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'PENDING\',
            attempts INT NOT NULL DEFAULT 0,
            available_at TIMESTAMPTZ NOT NULL,
            processed_at TIMESTAMPTZ NULL,
            last_error TEXT NULL,
            created_at TIMESTAMPTZ NULL,
            updated_at TIMESTAMPTZ NULL
        )');

        DB::statement('CREATE INDEX IF NOT EXISTS idx_inventory_outbox_status_available ON inventory.outbox_events (status, available_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_inventory_outbox_company_created ON inventory.outbox_events (company_id, created_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_inventory_outbox_event_type ON inventory.outbox_events (event_type)');
    }

    public static function enqueue(
        int $companyId,
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        array $payload
    ): int {
        self::ensureSchema();

        return (int) DB::table('inventory.outbox_events')->insertGetId([
            'company_id' => $companyId,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'event_type' => $eventType,
            'payload_json' => json_encode($payload),
            'status' => self::STATUS_PENDING,
            'attempts' => 0,
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function processPending(int $limit = 50): array
    {
        self::ensureSchema();

        $limit = max(1, min($limit, 500));

        $rows = DB::table('inventory.outbox_events')
            ->select('id', 'event_type', 'payload_json', 'attempts')
            ->where('status', self::STATUS_PENDING)
            ->where('available_at', '<=', now())
            ->orderBy('available_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $processed = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $eventId = (int) $row->id;

            DB::table('inventory.outbox_events')
                ->where('id', $eventId)
                ->where('status', self::STATUS_PENDING)
                ->update([
                    'status' => self::STATUS_PROCESSING,
                    'updated_at' => now(),
                ]);

            try {
                // Placeholder publisher. Replace with Kafka/SQS/EventBridge producer when extracted.
                self::publish((string) $row->event_type, $row->payload_json);

                DB::table('inventory.outbox_events')
                    ->where('id', $eventId)
                    ->update([
                        'status' => self::STATUS_PROCESSED,
                        'attempts' => ((int) $row->attempts) + 1,
                        'processed_at' => now(),
                        'last_error' => null,
                        'updated_at' => now(),
                    ]);

                $processed++;
            } catch (\Throwable $e) {
                DB::table('inventory.outbox_events')
                    ->where('id', $eventId)
                    ->update([
                        'status' => self::STATUS_PENDING,
                        'attempts' => ((int) $row->attempts) + 1,
                        'available_at' => now()->addMinute(),
                        'last_error' => mb_substr($e->getMessage(), 0, 1500),
                        'updated_at' => now(),
                    ]);

                $failed++;
            }
        }

        return [
            'selected' => $rows->count(),
            'processed' => $processed,
            'failed' => $failed,
        ];
    }

    private static function publish(string $eventType, $payload): void
    {
        $decoded = is_string($payload) ? json_decode($payload, true) : $payload;
        if ($decoded === null && is_string($payload) && json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid outbox payload for event ' . $eventType);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('Outbox payload must be a JSON object for event ' . $eventType);
        }

        ProjectionEngine::consume($eventType, $decoded);

        // TODO: add external publisher (Kafka/SQS/EventBridge) during service extraction.
    }
}
