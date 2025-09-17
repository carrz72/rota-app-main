<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SyntaxLintTest extends TestCase
{
    /**
     * @dataProvider phpFilesProvider
     */
    public function test_file_has_no_syntax_errors(string $file): void
    {
        $php = PHP_BINARY;
        $cmd = '"' . $php . '" -l ' . '"' . $file . '"';
        $output = [];
        $exitCode = 0;

        exec($cmd, $output, $exitCode);

        $this->assertSame(
            0,
            $exitCode,
            "Syntax error in {$file}:\n" . implode("\n", $output)
        );
    }

    public static function phpFilesProvider(): array
    {
        $roots = [
            __DIR__ . '/../functions',
            __DIR__ . '/../users',
            __DIR__ . '/../admin',
        ];

        $files = [];
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $path) {
                if ($path->isFile() && strtolower($path->getExtension()) === 'php') {
                    $files[] = [$path->getPathname()];
                }
            }
        }
        return $files;
    }
}
