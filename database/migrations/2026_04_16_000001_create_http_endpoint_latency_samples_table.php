<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class CreateHttpEndpointLatencySamplesTable extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS ops');

        DB::statement("CREATE TABLE IF NOT EXISTS ops.http_endpoint_latency_samples (
            id BIGSERIAL PRIMARY KEY,
            company_id BIGINT NULL,
            method VARCHAR(10) NOT NULL,
            route_uri VARCHAR(255) NOT NULL,
            endpoint_key VARCHAR(280) NOT NULL,
            status_code INTEGER NOT NULL,
            duration_ms NUMERIC(10,3) NOT NULL,
            requested_at TIMESTAMPTZ NOT NULL,
            created_at TIMESTAMPTZ NULL,
            updated_at TIMESTAMPTZ NULL
        )");

        DB::statement('CREATE INDEX IF NOT EXISTS idx_ops_latency_requested_at ON ops.http_endpoint_latency_samples (requested_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_ops_latency_company_requested_at ON ops.http_endpoint_latency_samples (company_id, requested_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_ops_latency_endpoint_requested_at ON ops.http_endpoint_latency_samples (endpoint_key, requested_at DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS ops.http_endpoint_latency_samples');
    }
}
