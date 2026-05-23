<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Performance indexes migration.
 *
 * Adds critical missing indexes identified in Railway performance audit (2026-05-23).
 * All operations use IF NOT EXISTS / DO NOTHING to be idempotent across environments.
 *
 * Impact:
 *  - auth.user_roles(user_id)          : role resolution on every authenticated request
 *  - auth.role_module_access(role_id)  : RBAC join on every APPCFG-guarded route
 *  - auth.user_module_overrides        : RBAC override lookup
 *  - appcfg.modules(code)              : module lookup by code string (per request)
 *  - appcfg.company_feature_toggles   : feature toggle lookup per request
 *  - sales.customers                   : customer search / autocomplete (high-frequency)
 *  - sales.commercial_documents        : main document listing composite
 *  - inventory.stock_entries           : dashboard metrics aggregation
 */
class AddPerformanceIndexes extends Migration
{
    public function up(): void
    {
        // ── auth.user_roles ──────────────────────────────────────────────────
        $this->execSafe("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_user_roles_user_id
            ON auth.user_roles (user_id)
        ");

        // ── auth.role_module_access ──────────────────────────────────────────
        $this->execSafe("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_role_module_access_role_module
            ON auth.role_module_access (role_id, module_id)
        ");

        // ── auth.user_module_overrides ───────────────────────────────────────
        $this->execSafe("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_user_module_overrides_user_module
            ON auth.user_module_overrides (user_id, module_id)
        ");

        // ── appcfg.modules(code) ─────────────────────────────────────────────
        $this->execSafe("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_appcfg_modules_code
            ON appcfg.modules (code)
            WHERE status = 1
        ");

        // ── appcfg.company_feature_toggles ───────────────────────────────────
        $this->execSafe("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_company_feature_toggles_company_code
            ON appcfg.company_feature_toggles (company_id, feature_code)
        ");

        // ── sales.customers: doc number search ───────────────────────────────
        $this->execSafe("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_sales_customers_company_doc
            ON sales.customers (company_id, doc_number)
        ");

        // ── sales.customers: name trigram search (requires pg_trgm) ──────────
        $this->execSafe("CREATE EXTENSION IF NOT EXISTS pg_trgm");
        $this->execSafe("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_sales_customers_legal_name_trgm
            ON sales.customers USING gin (legal_name gin_trgm_ops)
        ");

        // ── sales.commercial_documents: general-purpose listing composite ─────
        // (company_id, status, issue_at DESC) — covers most filtered listing queries
        $this->execSafe("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_commercial_docs_company_status_date
            ON sales.commercial_documents (company_id, status, issue_at DESC)
        ");

        // ── sales.commercial_documents: source_document_id for note JOINs ────
        $this->execSafe("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_commercial_docs_source_document
            ON sales.commercial_documents (source_document_id)
            WHERE source_document_id IS NOT NULL
        ");

        // ── inventory.stock_entries: dashboard aggregation ────────────────────
        $this->execSafe("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_stock_entries_company_date
            ON inventory.stock_entries (company_id, issue_at)
        ");
    }

    public function down(): void
    {
        $indexes = [
            'auth.user_roles'                  => 'idx_user_roles_user_id',
            'auth.role_module_access'          => 'idx_role_module_access_role_module',
            'auth.user_module_overrides'       => 'idx_user_module_overrides_user_module',
            'appcfg.modules'                   => 'idx_appcfg_modules_code',
            'appcfg.company_feature_toggles'  => 'idx_company_feature_toggles_company_code',
            'sales.customers'                  => ['idx_sales_customers_company_doc', 'idx_sales_customers_legal_name_trgm'],
            'sales.commercial_documents'       => ['idx_commercial_docs_company_status_date', 'idx_commercial_docs_source_document'],
            'inventory.stock_entries'          => 'idx_stock_entries_company_date',
        ];

        foreach ($indexes as $table => $names) {
            foreach ((array) $names as $name) {
                $this->execSafe("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
            }
        }
    }

    /**
     * Execute a raw SQL statement, ignoring errors for environments where
     * the table or extension may not exist yet.
     */
    private function execSafe(string $sql): void
    {
        try {
            DB::statement($sql);
        } catch (\Throwable $e) {
            // Log but do not fail — some tables may not exist in all environments
            \Log::warning("AddPerformanceIndexes: skipped — {$e->getMessage()}");
        }
    }
}
