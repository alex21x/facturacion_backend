<?php

namespace App\Infrastructure\Repositories\Sales\Documents;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

final class CommercialDocumentPrintCacheService
{
    /**
     * Cache TTL in seconds (24 hours)
     */
    private const CACHE_TTL_SECONDS = 86400;

    /**
     * Generate and store print templates for a newly created commercial document.
     * Stores HTML for both 'a4' and 'ticket' formats.
     *
     * @param int $companyId
     * @param int $documentId
     * @param callable $buildHtmlCallback Function that returns HTML given format: fn(string $format) => string
     * @return void
     */
    public function generateAndStorePrintTemplates(
        int $companyId,
        int $documentId,
        callable $buildHtmlCallback
    ): void {
        $expiresAt = Carbon::now()->addSeconds(self::CACHE_TTL_SECONDS);

        foreach (['a4', 'ticket'] as $format) {
            try {
                $html = $buildHtmlCallback($format);

                DB::table('sales.commercial_document_print_cache')
                    ->updateOrInsert(
                        [
                            'document_id' => $documentId,
                            'format' => $format,
                        ],
                        [
                            'company_id' => $companyId,
                            'html_content' => $html,
                            'created_at' => now(),
                            'expires_at' => $expiresAt,
                        ]
                    );
            } catch (\Throwable $e) {
                // Log error but don't fail document creation if caching fails
                \Log::warning(
                    'Failed to cache print template',
                    [
                        'document_id' => $documentId,
                        'company_id' => $companyId,
                        'format' => $format,
                        'error' => $e->getMessage(),
                    ]
                );
            }
        }
    }

    /**
     * Retrieve cached print template if available and not expired.
     *
     * @param int $documentId
     * @param string $format
     * @return string|null
     */
    public function getCachedHtml(int $documentId, string $format = 'ticket'): ?string
    {
        $format = strtolower(trim($format));
        if (!in_array($format, ['a4', 'ticket'], true)) {
            $format = 'ticket';
        }

        $row = DB::table('sales.commercial_document_print_cache')
            ->where('document_id', $documentId)
            ->where('format', $format)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
            ->first(['html_content']);

        return $row?->html_content;
    }

    /**
     * Invalidate cache for a specific document (e.g., when document is updated or voided).
     *
     * @param int $documentId
     * @return void
     */
    public function invalidateDocumentCache(int $documentId): void
    {
        DB::table('sales.commercial_document_print_cache')
            ->where('document_id', $documentId)
            ->delete();
    }

    /**
     * Clean up expired cache entries (run periodically).
     * 
     * @param int $companyId Optional company filter
     * @return int Number of rows deleted
     */
    public function cleanupExpiredCache(?int $companyId = null): int
    {
        $query = DB::table('sales.commercial_document_print_cache')
            ->where('expires_at', '<', now());

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        return $query->delete();
    }
}
