<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class ControllerLayeringGuardTest extends TestCase
{
    public function test_controllers_must_not_use_direct_db_or_inline_validator(): void
    {
        $controllersPath = app_path('Http/Controllers');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($controllersPath));

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = (string) $file->getPathname();
            $contents = (string) file_get_contents($path);

            $this->assertStringNotContainsString(
                'DB::table(',
                $contents,
                'Direct DB::table access is not allowed in controllers: ' . $path
            );

            $this->assertStringNotContainsString(
                'use Illuminate\\Support\\Facades\\DB;',
                $contents,
                'DB facade import is not allowed in controllers: ' . $path
            );

            $this->assertStringNotContainsString(
                'Validator::make(',
                $contents,
                'Inline Validator::make is not allowed in controllers: ' . $path
            );

            $this->assertStringNotContainsString(
                'use Illuminate\\Support\\Facades\\Validator;',
                $contents,
                'Validator facade import is not allowed in controllers: ' . $path
            );
        }
    }
}
