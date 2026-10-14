<?php

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

class LibraryLayerTest extends TestCase
{
    public function test_domain_has_no_framework_or_application_dependencies(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app/Domain', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            self::assertDoesNotMatchRegularExpression('/^use\\s+(?:Illuminate|App\\\\Application|App\\\\Adapters)\\\\/m', $source);
        }
    }
}
