<?php

namespace App\Console\Commands;

use App\Services\Sales\TaxBridge\TaxBridgeException;
use App\Services\Sales\TaxBridge\DailySummaryService;
use App\Services\Sales\TaxBridge\GreGuideService;
use App\Services\Sales\TaxBridge\TaxBridgeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairStuckSunatSending extends Command
{
    protected $signature = 'sales:repair-stuck-sending
        {--company-id= : Company ID scope}
        {--company-access-slug= : Company access slug scope (e.g. emp-xxxxxxxxxxxx)}
        {--scope=all : Scope to repair (documents,summaries,gre,all)}
        {--older-than-minutes=10 : Minimum age in minutes for SENDING rows}
        {--limit=500 : Maximum rows to process}
        {--dry-run : Only list rows, do not update}';

    protected $description = 'Recover records stuck in SUNAT SENDING status (documents, daily summaries, GRE guides).';

    public function handle(
        TaxBridgeService $taxBridgeService,
        DailySummaryService $dailySummaryService,
        GreGuideService $greGuideService
    ): int
    {
        $companyIdOpt = $this->option('company-id');
        $companyId = null;

        if ($companyIdOpt !== null && trim((string) $companyIdOpt) !== '') {
            $companyId = (int) $companyIdOpt;
            if ($companyId <= 0) {
                $this->error('company-id must be a positive integer.');
                return self::INVALID;
            }
        }

        $slug = trim((string) ($this->option('company-access-slug') ?? ''));
        if ($companyId === null && $slug !== '') {
            $companyId = $this->resolveCompanyIdByAccessSlug($slug);
            if ($companyId === null) {
                $this->error('No se pudo resolver company_id desde company-access-slug.');
                return self::FAILURE;
            }
        }

        $scopeRaw = trim(strtolower((string) ($this->option('scope') ?? 'all')));
        $scopeParts = array_filter(array_map('trim', explode(',', $scopeRaw)), static fn (string $part): bool => $part !== '');
        if (empty($scopeParts)) {
            $scopeParts = ['all'];
        }

        $validScopes = ['documents', 'summaries', 'gre', 'all'];
        foreach ($scopeParts as $scopePart) {
            if (!in_array($scopePart, $validScopes, true)) {
                $this->error('scope invalido. Usa: documents,summaries,gre,all');
                return self::INVALID;
            }
        }

        $runDocuments = in_array('all', $scopeParts, true) || in_array('documents', $scopeParts, true);
        $runSummaries = in_array('all', $scopeParts, true) || in_array('summaries', $scopeParts, true);
        $runGre = in_array('all', $scopeParts, true) || in_array('gre', $scopeParts, true);

        $olderThanMinutes = max(1, min(180, (int) $this->option('older-than-minutes')));
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $dryRun = (bool) $this->option('dry-run');

        $sections = [];

        try {
            if ($runDocuments) {
                $sections['documents'] = $taxBridgeService->recoverStuckSendingDocuments(
                    $companyId,
                    $slug !== '' ? $slug : null,
                    $olderThanMinutes,
                    $limit,
                    $dryRun
                );
            }

            if ($runSummaries) {
                $sections['summaries'] = $dailySummaryService->recoverStuckSendingSummaries(
                    $companyId,
                    $olderThanMinutes,
                    $limit,
                    $dryRun
                );
            }

            if ($runGre) {
                $sections['gre'] = $greGuideService->recoverStuckSendingGuides(
                    $companyId,
                    $olderThanMinutes,
                    $limit,
                    $dryRun
                );
            }
        } catch (TaxBridgeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $totalFound = 0;
        $totalAffected = 0;

        if (isset($sections['documents'])) {
            $result = $sections['documents'];
            $found = (int) ($result['found'] ?? 0);
            $affected = (int) ($result['affected'] ?? 0);
            $totalFound += $found;
            $totalAffected += $affected;

            $this->info(sprintf('[documents] found=%d affected=%d', $found, $affected));
            $documents = is_array($result['documents'] ?? null) ? $result['documents'] : [];
            if (!empty($documents)) {
                $preview = array_slice($documents, 0, 50);
                $this->table(
                    ['document_id', 'company_id', 'series', 'number', 'updated_at'],
                    array_map(static function (array $row): array {
                        return [
                            (string) ($row['document_id'] ?? ''),
                            (string) ($row['company_id'] ?? ''),
                            (string) ($row['series'] ?? ''),
                            (string) ($row['number'] ?? ''),
                            (string) ($row['updated_at'] ?? ''),
                        ];
                    }, $preview)
                );

                if (count($documents) > count($preview)) {
                    $this->line(sprintf('... %d more document rows omitted from preview', count($documents) - count($preview)));
                }
            }
        }

        if (isset($sections['summaries'])) {
            $result = $sections['summaries'];
            $found = (int) ($result['found'] ?? 0);
            $affected = (int) ($result['affected'] ?? 0);
            $totalFound += $found;
            $totalAffected += $affected;

            $this->info(sprintf('[summaries] found=%d affected=%d', $found, $affected));
            $summaries = is_array($result['summaries'] ?? null) ? $result['summaries'] : [];
            if (!empty($summaries)) {
                $preview = array_slice($summaries, 0, 50);
                $this->table(
                    ['summary_id', 'company_id', 'identifier', 'updated_at'],
                    array_map(static function (array $row): array {
                        return [
                            (string) ($row['summary_id'] ?? ''),
                            (string) ($row['company_id'] ?? ''),
                            (string) ($row['identifier'] ?? ''),
                            (string) ($row['updated_at'] ?? ''),
                        ];
                    }, $preview)
                );

                if (count($summaries) > count($preview)) {
                    $this->line(sprintf('... %d more summary rows omitted from preview', count($summaries) - count($preview)));
                }
            }
        }

        if (isset($sections['gre'])) {
            $result = $sections['gre'];
            $found = (int) ($result['found'] ?? 0);
            $affected = (int) ($result['affected'] ?? 0);
            $totalFound += $found;
            $totalAffected += $affected;

            $this->info(sprintf('[gre] found=%d affected=%d', $found, $affected));
            $guides = is_array($result['guides'] ?? null) ? $result['guides'] : [];
            if (!empty($guides)) {
                $preview = array_slice($guides, 0, 50);
                $this->table(
                    ['guide_id', 'company_id', 'identifier', 'updated_at'],
                    array_map(static function (array $row): array {
                        return [
                            (string) ($row['guide_id'] ?? ''),
                            (string) ($row['company_id'] ?? ''),
                            (string) ($row['identifier'] ?? ''),
                            (string) ($row['updated_at'] ?? ''),
                        ];
                    }, $preview)
                );

                if (count($guides) > count($preview)) {
                    $this->line(sprintf('... %d more GRE rows omitted from preview', count($guides) - count($preview)));
                }
            }
        }

        $this->info(sprintf(
            'Repair done. total_found=%d total_affected=%d dry_run=%s company_id=%s slug=%s scope=%s',
            $totalFound,
            $totalAffected,
            $dryRun ? 'true' : 'false',
            $companyId !== null ? (string) $companyId : 'ALL',
            $slug !== '' ? $slug : 'N/A',
            implode(',', $scopeParts)
        ));

        return self::SUCCESS;
    }

    private function resolveCompanyIdByAccessSlug(string $slug): ?int
    {
        $normalized = strtolower(trim($slug));
        if ($normalized === '') {
            return null;
        }

        $tableExists = DB::table('information_schema.tables')
            ->where('table_schema', 'appcfg')
            ->where('table_name', 'company_access_links')
            ->exists();

        if (!$tableExists) {
            return null;
        }

        $row = DB::table('appcfg.company_access_links')
            ->whereRaw('LOWER(access_slug) = ?', [$normalized])
            ->select('company_id')
            ->first();

        return $row && isset($row->company_id) ? (int) $row->company_id : null;
    }
}
