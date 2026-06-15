<?php

namespace App\Application\UseCases\Sales;

use App\Services\Sales\SalesLookupService;
use Illuminate\Support\Facades\Storage;

class ResolveCompanyPrintProfileUseCase
{
    public function __construct(private SalesLookupService $salesLookupService)
    {
    }

    public function execute(int $companyId): array
    {
        $companyColumns = $this->tableColumns('core.companies');
        $companyEmailColumn = $this->firstExistingColumn($companyColumns, ['email', 'contact_email']);

        $companySelect = ['tax_id', 'legal_name', 'trade_name'];
        if ($companyEmailColumn) {
            $companySelect[] = $companyEmailColumn;
        }

        $company = $this->salesLookupService->findCompanyById($companyId, $companySelect);

        $settings = null;
        if ($this->tableExists('core.company_settings')) {
            $settingColumns = $this->tableColumns('core.company_settings');
            $settingEmailColumn = $this->firstExistingColumn($settingColumns, ['email', 'contact_email']);

            $settingsSelect = ['address', 'phone', 'logo_path', 'bank_accounts', 'extra_data'];
            if ($settingEmailColumn) {
                $settingsSelect[] = $settingEmailColumn;
            }

            $settings = $this->salesLookupService->findLatestCompanySettings(
                $companyId,
                $settingsSelect,
                in_array('logo_path', $settingColumns, true),
                in_array('updated_at', $settingColumns, true),
                in_array('created_at', $settingColumns, true)
            );
        }

        $companyEmail = null;
        if ($settings) {
            $companyEmail = (string) ($settings->email ?? $settings->contact_email ?? '');
        }
        if ($companyEmail === null || trim($companyEmail) === '') {
            $companyEmail = (string) ($company->email ?? $company->contact_email ?? '');
        }
        $companyEmail = trim($companyEmail) !== '' ? trim($companyEmail) : null;

        $extraData = [];
        if ($settings && isset($settings->extra_data)) {
            $decodedExtra = json_decode((string) $settings->extra_data, true);
            $extraData = is_array($decodedExtra) ? $decodedExtra : [];
        }

        $logoDataUri = null;
        if (isset($extraData['company_logo_data_uri'])) {
            $candidateDataUri = trim((string) $extraData['company_logo_data_uri']);
            if (preg_match('/^data:image\//i', $candidateDataUri) === 1) {
                $logoDataUri = $candidateDataUri;
            }
        }

        $logoPath = $settings->logo_path ?? null;
        $logoNormalizedPath = $this->normalizeCompanyLogoStoragePath($logoPath);
        $logoExistsInStorage = $logoNormalizedPath ? $this->publicStorageLogoExists($logoNormalizedPath) : false;

        if ($logoDataUri === null && $logoNormalizedPath && $logoExistsInStorage) {
            $generatedDataUri = $this->companyLogoDataUriFromPublicStorage($logoNormalizedPath);
            if ($generatedDataUri !== null) {
                $logoDataUri = $generatedDataUri;
                $extraData['company_logo_data_uri'] = $generatedDataUri;

                if ($this->tableExists('core.company_settings')) {
                    $settingsUpdates = ['extra_data' => json_encode($extraData)];
                    $companySettingsColumns = $this->tableColumns('core.company_settings');
                    if (in_array('updated_at', $companySettingsColumns, true)) {
                        $settingsUpdates['updated_at'] = now();
                    }

                    $this->salesLookupService->updateCompanySettings($companyId, $settingsUpdates);
                }
            }
        }

        $logoUrl = $logoExistsInStorage
            ? $this->resolveCompanyLogoUrl($logoPath)
            : null;

        if (($logoUrl === null || $logoUrl === '') && $logoDataUri !== null) {
            $logoUrl = $logoDataUri;
        }

        if (($logoUrl === null || $logoUrl === '') && $logoPath !== null) {
            $logoUrl = $this->resolveCompanyLogoUrl($logoPath);
        }

        $bankAccounts = [];
        if ($settings && isset($settings->bank_accounts)) {
            $decodedBanks = json_decode((string) $settings->bank_accounts, true);
            if (is_array($decodedBanks)) {
                $bankAccounts = array_values(array_filter($decodedBanks, static fn ($item) => is_array($item)));
            }
        }

        return [
            'company_id' => $companyId,
            'tax_id'     => $company->tax_id ?? null,
            'legal_name' => $company->legal_name ?? '',
            'trade_name' => $company->trade_name ?? null,
            'company_description' => isset($extraData['company_description']) ? trim((string) $extraData['company_description']) : null,
            'address'    => $settings->address ?? null,
            'phone'      => $settings->phone ?? null,
            'email'      => $companyEmail,
            'logo_url'   => $logoUrl,
            'logo_data_uri' => $logoDataUri,
            'show_payment_brand_icons' => array_key_exists('show_payment_brand_icons', $extraData)
                ? filter_var($extraData['show_payment_brand_icons'], FILTER_VALIDATE_BOOLEAN)
                : true,
            'bank_accounts' => $bankAccounts,
        ];
    }

    private function tableExists(string $qualifiedTable): bool
    {
        return $this->salesLookupService->tableExists($qualifiedTable);
    }

    private function tableColumns(string $qualifiedTable): array
    {
        return $this->salesLookupService->tableColumns($qualifiedTable);
    }

    private function firstExistingColumn(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveCompanyLogoUrl($logoPath): ?string
    {
        $raw = trim((string) ($logoPath ?? ''));
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $raw) === 1) {
            $resolved = $this->rewriteLocalAbsoluteUrlToRequestHost($raw);
            return $this->appendLocalLogoVersion($resolved, null);
        }

        $normalized = str_replace('\\', '/', $raw);
        if (preg_match('/^https?:\/\//i', $normalized) === 1) {
            $pathFromUrl = parse_url($normalized, PHP_URL_PATH);
            $normalized = $pathFromUrl !== null ? (string) $pathFromUrl : $normalized;
        }

        $normalized = ltrim($normalized, '/');
        if (str_starts_with($normalized, 'storage/')) {
            $normalized = ltrim(substr($normalized, strlen('storage/')), '/');
        }

        if ($normalized === '') {
            return null;
        }

        $resolved = url('/storage/' . $normalized);
        try {
            if (Storage::disk('public')->exists($normalized)) {
                $resolved = url('/storage/' . $normalized);
            }
        } catch (\Throwable $e) {
            // Ignore storage checks to avoid hiding logo paths during transient IO issues.
        }

        $resolved = $this->rewriteLocalAbsoluteUrlToRequestHost($resolved);
        return $this->appendLocalLogoVersion($resolved, $normalized);
    }

    private function rewriteLocalAbsoluteUrlToRequestHost(string $url): string
    {
        if (!$this->isLocalEnvironment()) {
            return $url;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (!in_array($host, ['127.0.0.1', 'localhost', '0.0.0.0'], true)) {
            return $url;
        }

        $request = request();
        if (!$request) {
            return $url;
        }

        $requestHost = trim((string) $request->getHost());
        if ($requestHost === '') {
            return $url;
        }

        $scheme = (string) (parse_url($url, PHP_URL_SCHEME) ?? $request->getScheme());
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $query = (string) (parse_url($url, PHP_URL_QUERY) ?? '');
        $port = parse_url($url, PHP_URL_PORT);
        if ($port === null) {
            $port = $request->getPort();
        }

        $portSuffix = '';
        if (is_int($port) && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
            $portSuffix = ':' . $port;
        }

        $rebuilt = $scheme . '://' . $requestHost . $portSuffix . $path;
        if ($query !== '') {
            $rebuilt .= '?' . $query;
        }

        return $rebuilt;
    }

    private function appendLocalLogoVersion(string $url, ?string $normalizedPath): string
    {
        if (!$this->isLocalEnvironment() || $normalizedPath === null || trim($normalizedPath) === '') {
            return $url;
        }

        try {
            $absolutePath = storage_path('app/public/' . ltrim($normalizedPath, '/'));
            if (!is_file($absolutePath)) {
                return $url;
            }

            $version = (string) @filemtime($absolutePath);
            if ($version === '' || $version === '0') {
                return $url;
            }

            return strpos($url, '?') === false
                ? $url . '?v=' . $version
                : $url . '&v=' . $version;
        } catch (\Throwable $e) {
            return $url;
        }
    }

    private function isLocalEnvironment(): bool
    {
        return strtolower((string) env('APP_ENV', 'production')) === 'local';
    }

    private function normalizeCompanyLogoStoragePath($logoPath): ?string
    {
        $raw = trim((string) ($logoPath ?? ''));
        if ($raw === '') {
            return null;
        }

        $normalized = str_replace('\\', '/', $raw);
        if (preg_match('/^https?:\/\//i', $normalized) === 1) {
            $pathFromUrl = parse_url($normalized, PHP_URL_PATH);
            $normalized = $pathFromUrl !== null ? (string) $pathFromUrl : $normalized;
        }

        $normalized = ltrim($normalized, '/');
        if (str_starts_with($normalized, 'storage/')) {
            $normalized = ltrim(substr($normalized, strlen('storage/')), '/');
        }
        if (str_starts_with($normalized, 'public/')) {
            $normalized = ltrim(substr($normalized, strlen('public/')), '/');
        }

        return $normalized !== '' ? $normalized : null;
    }

    private function publicStorageLogoExists(string $normalizedPath): bool
    {
        try {
            return Storage::disk('public')->exists($normalizedPath);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function companyLogoDataUriFromPublicStorage(string $normalizedPath): ?string
    {
        try {
            if (!Storage::disk('public')->exists($normalizedPath)) {
                return null;
            }

            $absolutePath = Storage::disk('public')->path($normalizedPath);
            $binary = @file_get_contents($absolutePath);
            if ($binary === false || $binary === '') {
                return null;
            }

            $extension = strtolower((string) pathinfo($absolutePath, PATHINFO_EXTENSION));
            $mimeType = match ($extension) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                default => '',
            };

            if ($mimeType === '') {
                $finfo = @finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo !== false) {
                    $detected = @finfo_file($finfo, $absolutePath);
                    @finfo_close($finfo);
                    $mimeType = is_string($detected) ? trim($detected) : '';
                }
            }

            if ($mimeType === '' || !str_starts_with($mimeType, 'image/')) {
                $mimeType = 'image/png';
            }

            return 'data:' . $mimeType . ';base64,' . base64_encode($binary);
        } catch (\Throwable $e) {
            return null;
        }
    }

}
