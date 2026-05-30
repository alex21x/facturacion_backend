<?php

namespace App\Services\AppConfig;

use App\Infrastructure\Repositories\AppConfig\HomeMetricsSummaryRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class HomeMetricsSummaryService
{
    public function __construct(
        private HomeMetricsSummaryRepository $homeMetricsSummaryRepository
    ) {
    }

    public function buildSummary(int $companyId, string $range, ?int $branchId, ?int $warehouseId): array
    {
        $normalizedRange = $this->normalizeHomeMetricsRange($range);

        $cacheKey = implode(':', [
            'appcfg',
            'home-metrics-summary',
            $companyId,
            $normalizedRange,
            $branchId ?? 'all',
            $warehouseId ?? 'all',
        ]);

        $cachedPayload = Cache::get($cacheKey);
        if (is_array($cachedPayload)) {
            return $cachedPayload;
        }

        $now = Carbon::now('America/Lima');
        $from = $now->copy();
        $to = $now->copy()->endOfDay();
        $salesBucketExpr = "to_char(date_trunc('day', d.issue_at), 'YYYY-MM-DD')";
        $purchaseBucketExpr = "to_char(date_trunc('day', se.issue_at), 'YYYY-MM-DD')";

        if ($normalizedRange === 'MONTH') {
            $from = $now->copy()->subMonths(5)->startOfMonth();
            $salesBucketExpr = "to_char(date_trunc('month', d.issue_at), 'YYYY-MM')";
            $purchaseBucketExpr = "to_char(date_trunc('month', se.issue_at), 'YYYY-MM')";
        } elseif ($normalizedRange === 'YEAR') {
            $from = $now->copy()->subYears(2)->startOfYear();
            $salesBucketExpr = "to_char(date_trunc('year', d.issue_at), 'YYYY')";
            $purchaseBucketExpr = "to_char(date_trunc('year', se.issue_at), 'YYYY')";
        } else {
            $from = $now->copy()->subDays(6)->startOfDay();
        }

        $pointKeys = $this->buildHomeMetricPointKeys($normalizedRange, $from, $to);
        $pointsMap = [];

        foreach ($pointKeys as $key) {
            $pointsMap[$key] = [
                'key' => $key,
                'label' => $this->formatHomeMetricPointLabel($key, $normalizedRange),
                'sales' => 0.0,
                'purchases' => 0.0,
            ];
        }

        $salesRows = $this->homeMetricsSummaryRepository->aggregateSalesByBucket(
            $companyId,
            $salesBucketExpr,
            $from->toDateTimeString(),
            $to->toDateTimeString(),
            $branchId,
            $warehouseId
        );

        $purchaseRows = $this->homeMetricsSummaryRepository->aggregatePurchasesByBucket(
            $companyId,
            $purchaseBucketExpr,
            $from->toDateTimeString(),
            $to->toDateTimeString(),
            $branchId,
            $warehouseId
        );

        foreach ($salesRows as $bucketKey => $amount) {
            $key = (string) $bucketKey;
            if (!array_key_exists($key, $pointsMap)) {
                continue;
            }

            $pointsMap[$key]['sales'] = round((float) $amount, 2);
        }

        foreach ($purchaseRows as $bucketKey => $amount) {
            $key = (string) $bucketKey;
            if (!array_key_exists($key, $pointsMap)) {
                continue;
            }

            $pointsMap[$key]['purchases'] = round((float) $amount, 2);
        }

        $points = array_values($pointsMap);

        $payload = [
            'range' => $normalizedRange,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'points' => $points,
            'totals' => [
                'sales' => round((float) array_sum(array_column($points, 'sales')), 2),
                'purchases' => round((float) array_sum(array_column($points, 'purchases')), 2),
            ],
        ];

        Cache::put($cacheKey, $payload, now()->addSeconds(45));

        return $payload;
    }

    private function normalizeHomeMetricsRange(string $range): string
    {
        $normalized = strtoupper(trim($range));
        if (!in_array($normalized, ['DAY', 'MONTH', 'YEAR'], true)) {
            return 'DAY';
        }

        return $normalized;
    }

    private function buildHomeMetricPointKeys(string $range, Carbon $from, Carbon $to): array
    {
        $keys = [];
        $cursor = $from->copy();

        if ($range === 'YEAR') {
            while ($cursor->lte($to)) {
                $keys[] = $cursor->format('Y');
                $cursor->addYear()->startOfYear();
            }

            return $keys;
        }

        if ($range === 'MONTH') {
            while ($cursor->lte($to)) {
                $keys[] = $cursor->format('Y-m');
                $cursor->addMonth()->startOfMonth();
            }

            return $keys;
        }

        while ($cursor->lte($to)) {
            $keys[] = $cursor->format('Y-m-d');
            $cursor->addDay()->startOfDay();
        }

        return $keys;
    }

    private function formatHomeMetricPointLabel(string $key, string $range): string
    {
        if ($range === 'YEAR') {
            return $key;
        }

        if ($range === 'MONTH') {
            $point = Carbon::createFromFormat('Y-m', $key, 'America/Lima');
            return $point->format('m/Y');
        }

        $point = Carbon::createFromFormat('Y-m-d', $key, 'America/Lima');
        return $point->format('d/m');
    }
}
