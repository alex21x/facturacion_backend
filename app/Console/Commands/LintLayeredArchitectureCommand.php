<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class LintLayeredArchitectureCommand extends Command
{
    protected $signature = 'architecture:lint-layering
        {--path=* : One or more relative paths to scan (default: app/Http/Controllers and app/Services)}
        {--strict : Fail command when violations are found}';

    protected $description = 'Detects DB::/Http:: usage in layers that must remain clean (controllers/services).';

    /**
     * @var array<int, array{label:string,root:string,patterns:array<int,string>,allow:array<int,string>}>
     */
    private array $rules = [
        [
            'label' => 'controllers',
            'root' => 'app/Http/Controllers',
            'patterns' => ['DB::', 'Http::'],
            'allow' => [],
        ],
        [
            'label' => 'services',
            'root' => 'app/Services',
            'patterns' => ['DB::', 'Http::'],
            'allow' => [
                'app/Services/*/*Gateway.php',
                'app/Services/*/*/*Gateway.php',
                'app/Services/Sales/TaxBridge/*.php',
            ],
        ],
    ];

    public function handle(): int
    {
        $paths = $this->resolveScanPaths();
        $violations = [];

        foreach ($paths as $relativePath) {
            $absolutePath = base_path($relativePath);
            if (!is_dir($absolutePath)) {
                if (is_file($absolutePath)) {
                    $violations = array_merge($violations, $this->scanFileByRule($relativePath));
                }
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolutePath, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $item) {
                if (!$item->isFile()) {
                    continue;
                }

                if (strtolower($item->getExtension()) !== 'php') {
                    continue;
                }

                $relativeFile = $this->normalizePath(str_replace(base_path() . DIRECTORY_SEPARATOR, '', $item->getPathname()));
                $violations = array_merge($violations, $this->scanFileByRule($relativeFile));
            }
        }

        if (empty($violations)) {
            $this->info('Layering lint passed: no violations detected.');
            return self::SUCCESS;
        }

        $this->warn('Layering lint found violations: ' . count($violations));
        foreach ($violations as $violation) {
            $this->line(sprintf(
                '- %s:%d [%s] contains %s',
                $violation['file'],
                $violation['line'],
                $violation['layer'],
                $violation['pattern']
            ));
        }

        if ($this->option('strict')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function resolveScanPaths(): array
    {
        $input = $this->option('path');
        if (!is_array($input) || empty($input)) {
            return ['app/Http/Controllers', 'app/Services'];
        }

        return array_values(array_unique(array_map(fn ($p) => $this->normalizePath((string) $p), $input)));
    }

    /**
     * @return array<int, array{file:string,line:int,layer:string,pattern:string}>
     */
    private function scanFileByRule(string $relativeFile): array
    {
        $rule = $this->matchRuleForFile($relativeFile);
        if ($rule === null) {
            return [];
        }

        foreach ($rule['allow'] as $glob) {
            if (fnmatch($this->normalizePath($glob), $relativeFile)) {
                return [];
            }
        }

        $absoluteFile = base_path($relativeFile);
        if (!is_file($absoluteFile)) {
            return [];
        }

        $lines = @file($absoluteFile, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return [];
        }

        $hits = [];
        foreach ($lines as $index => $line) {
            foreach ($rule['patterns'] as $pattern) {
                if (strpos($line, $pattern) !== false) {
                    $hits[] = [
                        'file' => $relativeFile,
                        'line' => $index + 1,
                        'layer' => $rule['label'],
                        'pattern' => $pattern,
                    ];
                }
            }
        }

        return $hits;
    }

    /**
     * @return array{label:string,root:string,patterns:array<int,string>,allow:array<int,string>}|null
     */
    private function matchRuleForFile(string $relativeFile): ?array
    {
        foreach ($this->rules as $rule) {
            $root = $this->normalizePath($rule['root']);
            if (str_starts_with($relativeFile, $root . '/')) {
                return $rule;
            }
        }

        return null;
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', trim($path));
    }
}
