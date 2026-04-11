<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportLegacyUbigeoCommand extends Command
{
    protected $signature = 'sunat:import-ubigeo {--file= : SQL dump file path with COPY master.geo_ubigeo data}';

    protected $description = 'Importa ubigeos SUNAT a core.ubigeos desde tabla legacy o dump SQL';

    public function handle(): int
    {
        $this->info('Iniciando importacion de ubigeos...');

        if ($this->importFromLegacyTable()) {
            return self::SUCCESS;
        }

        $file = $this->resolveSqlFile();
        if ($file === null) {
            $this->error('No se encontro fuente para importar ubigeos (tabla legacy ni dump SQL).');
            return self::FAILURE;
        }

        $this->info('Importando desde dump: ' . $file);
        $rows = $this->parseDump($file);

        if (count($rows) === 0) {
            $this->error('No se encontraron filas de COPY master.geo_ubigeo en el dump.');
            return self::FAILURE;
        }

        $this->persistRows($rows);
        $this->info('Ubigeos importados: ' . count($rows));

        return self::SUCCESS;
    }

    private function importFromLegacyTable(): bool
    {
        $exists = DB::table('information_schema.tables')
            ->where('table_schema', 'master')
            ->where('table_name', 'geo_ubigeo')
            ->exists();

        if (!$exists) {
            return false;
        }

        $this->info('Se detecto tabla legacy master.geo_ubigeo; importando...');

        $rows = DB::table('master.geo_ubigeo')
            ->select('code', 'full_name', 'status')
            ->whereNotNull('code')
            ->orderBy('code')
            ->get();

        $normalized = [];
        foreach ($rows as $row) {
            $parsed = $this->parseLegacyFullName((string) ($row->full_name ?? ''), (string) ($row->code ?? ''));
            if ($parsed === null) {
                continue;
            }
            $normalized[] = [
                'code' => $parsed['code'],
                'district' => $parsed['district'],
                'province' => $parsed['province'],
                'department' => $parsed['department'],
                'full_name' => $parsed['full_name'],
                'status' => (int) ($row->status ?? 1),
            ];
        }

        if (count($normalized) === 0) {
            return false;
        }

        $this->persistRows($normalized);
        $this->info('Ubigeos importados desde tabla legacy: ' . count($normalized));

        return true;
    }

    private function resolveSqlFile(): ?string
    {
        $optionFile = trim((string) $this->option('file'));
        if ($optionFile !== '' && is_file($optionFile)) {
            return $optionFile;
        }

        $files = glob(base_path('facturacion_v2_export*.sql')) ?: [];
        if (empty($files)) {
            return null;
        }

        rsort($files);
        foreach ($files as $file) {
            if (is_file($file) && filesize($file) > 0) {
                return $file;
            }
        }

        return null;
    }

    private function parseDump(string $file): array
    {
        $handle = fopen($file, 'r');
        if ($handle === false) {
            return [];
        }

        $inCopy = false;
        $rows = [];

        while (($line = fgets($handle)) !== false) {
            if (!$inCopy) {
                if (stripos($line, 'COPY master.geo_ubigeo') !== false) {
                    $inCopy = true;
                }
                continue;
            }

            $trimmed = trim($line);
            if ($trimmed === '\\.') {
                break;
            }

            if ($trimmed === '') {
                continue;
            }

            $parts = explode("\t", rtrim($line, "\r\n"));
            if (count($parts) < 3) {
                continue;
            }

            $code = (string) ($parts[1] ?? '');
            $fullName = (string) ($parts[2] ?? '');
            $status = isset($parts[7]) ? (int) $parts[7] : 1;

            $parsed = $this->parseLegacyFullName($fullName, $code);
            if ($parsed === null) {
                continue;
            }

            $rows[] = [
                'code' => $parsed['code'],
                'district' => $parsed['district'],
                'province' => $parsed['province'],
                'department' => $parsed['department'],
                'full_name' => $parsed['full_name'],
                'status' => $status,
            ];
        }

        fclose($handle);

        return $rows;
    }

    private function parseLegacyFullName(string $fullName, string $code): ?array
    {
        $cleanCode = preg_replace('/\D+/', '', $code) ?? '';
        if (strlen($cleanCode) !== 6) {
            return null;
        }

        $value = trim($fullName);
        if ($value === '') {
            return null;
        }

        $parts = explode('|', $value);
        if (count($parts) < 3) {
            return null;
        }

        $districtPart = trim((string) $parts[0]);
        $districtPart = preg_replace('/^\d{6}\s*\-\s*/', '', $districtPart) ?? $districtPart;

        $province = trim((string) $parts[1]);
        $department = trim((string) $parts[2]);
        $department = preg_replace('/^DEPARTAMENTO\s+/i', '', $department) ?? $department;

        return [
            'code' => $cleanCode,
            'district' => mb_strtoupper($districtPart, 'UTF-8'),
            'province' => mb_strtoupper($province, 'UTF-8'),
            'department' => mb_strtoupper($department, 'UTF-8'),
            'full_name' => sprintf('%s - %s|%s|DEPARTAMENTO %s', $cleanCode, mb_strtoupper($districtPart, 'UTF-8'), mb_strtoupper($province, 'UTF-8'), mb_strtoupper($department, 'UTF-8')),
        ];
    }

    private function persistRows(array $rows): void
    {
        foreach ($rows as $row) {
            DB::table('core.ubigeos')->updateOrInsert(
                ['code' => $row['code']],
                [
                    'district' => $row['district'],
                    'province' => $row['province'],
                    'department' => $row['department'],
                    'full_name' => $row['full_name'],
                    'status' => $row['status'],
                    'source' => 'legacy',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
