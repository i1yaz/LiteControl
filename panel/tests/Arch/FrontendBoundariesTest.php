<?php

declare(strict_types=1);

function jsRoot(): string
{
    return str_replace('\\', '/', dirname(__DIR__, 2).'/resources/js');
}

/**
 * @return list<string>
 */
function frontendSubdirectories(string $baseDir): array
{
    if (! is_dir($baseDir)) {
        return [];
    }

    $dirs = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            $dirs[] = str_replace('\\', '/', $item->getPathname());
        }
    }

    return $dirs;
}

/**
 * @return list<string>
 */
function frontendSourceFiles(string $baseDir): array
{
    if (! is_dir($baseDir)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $item) {
        if (! $item->isFile()) {
            continue;
        }

        $ext = strtolower($item->getExtension());
        if (in_array($ext, ['vue', 'js'], true)) {
            $files[] = str_replace('\\', '/', $item->getPathname());
        }
    }

    return $files;
}

/**
 * @return list<string>
 */
function extractImportSpecifiers(string $contents): array
{
    preg_match_all(
        '/(?:^|[\s;])import(?:\s+[\s\S]*?\s+from\s+|\s*\(\s*|\s+)[\'"]([^\'"]+)[\'"]/m',
        $contents,
        $matches
    );

    return $matches[1] ?? [];
}

function normalizePosixPath(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $prefix = '';
    if (preg_match('/^[A-Za-z]:/', $path, $m) === 1) {
        $prefix = $m[0];
        $path = substr($path, 2);
    }

    $parts = explode('/', $path);
    $stack = [];
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($stack);

            continue;
        }
        $stack[] = $part;
    }

    return $prefix.'/'.implode('/', $stack);
}

function resolveImportToJsRelativePath(string $specifier, string $importerFile, string $root): ?string
{
    $root = rtrim(normalizePosixPath($root), '/');

    if (str_starts_with($specifier, '@domains/')) {
        return 'Domains/'.substr($specifier, strlen('@domains/'));
    }

    if (str_starts_with($specifier, '@shared/')) {
        return 'Shared/'.substr($specifier, strlen('@shared/'));
    }

    if (str_starts_with($specifier, '@/')) {
        return substr($specifier, strlen('@/'));
    }

    if (str_starts_with($specifier, './') || str_starts_with($specifier, '../')) {
        $resolved = normalizePosixPath(dirname($importerFile).'/'.$specifier);
        if (str_starts_with($resolved, $root.'/')) {
            return substr($resolved, strlen($root) + 1);
        }
    }

    return null;
}

test('legacy root directories do not exist in resources/js', function () {
    $root = jsRoot();
    $legacyDirs = ['Components', 'Composables', 'Config', 'Layouts', 'Navigation', 'Utils'];

    foreach ($legacyDirs as $dir) {
        expect(is_dir("{$root}/{$dir}"))
            ->toBeFalse("Legacy root directory resources/js/{$dir} must not exist.");
    }
});

test('structural directories under Pages, Domains, and Shared use PascalCase', function () {
    $root = jsRoot();

    foreach (['Pages', 'Domains', 'Shared'] as $section) {
        foreach (frontendSubdirectories("{$root}/{$section}") as $dirPath) {
            $basename = basename($dirPath);
            expect($basename)
                ->toMatch('/^[A-Z][a-zA-Z0-9]*$/', "Directory {$dirPath} must use PascalCase.");
        }
    }
});

test('top-level directories in Pages and Domains match canonical Bounded Contexts', function () {
    $root = jsRoot();
    $canonicalContexts = [
        'WebServer',
        'PhpRuntime',
        'ServerOps',
        'PanelConfig',
        'Identity',
        'HostingPlans',
        'Database',
        'Dns',
        'Domains',
        'Backup',
        'FileManager',
        'Security',
        'Cron',
    ];

    foreach (['Pages', 'Domains'] as $section) {
        $sectionPath = "{$root}/{$section}";
        if (! is_dir($sectionPath)) {
            continue;
        }

        foreach (scandir($sectionPath) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir("{$sectionPath}/{$entry}")) {
                expect($entry)
                    ->toBeIn($canonicalContexts, "Unexpected context directory {$section}/{$entry}.");
            }
        }
    }
});

test('Shared files never import from Domains or Pages', function () {
    $root = jsRoot();
    $violations = [];

    foreach (frontendSourceFiles("{$root}/Shared") as $file) {
        $contents = file_get_contents($file) ?: '';
        foreach (extractImportSpecifiers($contents) as $specifier) {
            $resolved = resolveImportToJsRelativePath($specifier, $file, $root);
            if ($resolved !== null && (str_starts_with($resolved, 'Domains/') || str_starts_with($resolved, 'Pages/'))) {
                $violations[] = "{$file} imports '{$specifier}' (resolved: {$resolved})";
            }
        }
    }

    expect($violations)->toBeEmpty(implode("\n", $violations));
});

test('Domains files respect bounded context isolation and never import from Pages', function () {
    $root = jsRoot();
    $violations = [];

    foreach (frontendSourceFiles("{$root}/Domains") as $file) {
        $relPath = substr($file, strlen($root) + 1);
        $contextA = explode('/', $relPath)[1] ?? '';

        $contents = file_get_contents($file) ?: '';
        foreach (extractImportSpecifiers($contents) as $specifier) {
            $resolved = resolveImportToJsRelativePath($specifier, $file, $root);
            if ($resolved === null) {
                continue;
            }

            if (str_starts_with($resolved, 'Pages/')) {
                $violations[] = "{$relPath} imports from Pages: '{$specifier}'";

                continue;
            }

            if (str_starts_with($resolved, 'Domains/')) {
                $contextB = explode('/', $resolved)[1] ?? '';
                if ($contextB !== '' && $contextB !== $contextA) {
                    $violations[] = "{$relPath} ({$contextA}) imports cross-domain '{$specifier}' ({$contextB})";
                }
            }
        }
    }

    expect($violations)->toBeEmpty(implode("\n", $violations));
});

test('Pages files respect bounded context isolation', function () {
    $root = jsRoot();
    $violations = [];

    foreach (frontendSourceFiles("{$root}/Pages") as $file) {
        $relPath = substr($file, strlen($root) + 1);
        $contextA = explode('/', $relPath)[1] ?? '';

        $contents = file_get_contents($file) ?: '';
        foreach (extractImportSpecifiers($contents) as $specifier) {
            $resolved = resolveImportToJsRelativePath($specifier, $file, $root);
            if ($resolved === null) {
                continue;
            }

            if (str_starts_with($resolved, 'Domains/') || str_starts_with($resolved, 'Pages/')) {
                $contextB = explode('/', $resolved)[1] ?? '';
                if ($contextB !== '' && $contextB !== $contextA) {
                    $violations[] = "{$relPath} ({$contextA}) imports cross-context '{$specifier}' ({$contextB})";
                }
            }
        }
    }

    expect($violations)->toBeEmpty(implode("\n", $violations));
});

test('Pages and Domains never import directly from Themes', function () {
    $root = jsRoot();
    $violations = [];

    foreach (['Pages', 'Domains'] as $section) {
        foreach (frontendSourceFiles("{$root}/{$section}") as $file) {
            $relPath = substr($file, strlen($root) + 1);
            $contents = file_get_contents($file) ?: '';

            foreach (extractImportSpecifiers($contents) as $specifier) {
                $resolved = resolveImportToJsRelativePath($specifier, $file, $root);
                if ($resolved !== null && str_starts_with($resolved, 'Themes/')) {
                    $violations[] = "{$relPath} imports directly from Themes: '{$specifier}'";
                }
            }
        }
    }

    expect($violations)->toBeEmpty(implode("\n", $violations));
});

test('every theme has required contract files and valid page overrides', function () {
    $root = jsRoot();
    $themesDir = "{$root}/Themes";

    expect(is_dir($themesDir))->toBeTrue();

    foreach (scandir($themesDir) as $theme) {
        if ($theme === '.' || $theme === '..') {
            continue;
        }

        $themePath = "{$themesDir}/{$theme}";
        if (! is_dir($themePath)) {
            continue;
        }

        foreach (['theme.json', 'theme.css', 'index.js'] as $requiredFile) {
            expect(is_file("{$themePath}/{$requiredFile}"))
                ->toBeTrue("Theme '{$theme}' is missing required file '{$requiredFile}'.");
        }

        $themePagesDir = "{$themePath}/Pages";
        foreach (frontendSourceFiles($themePagesDir) as $overrideFile) {
            $relativePage = substr($overrideFile, strlen($themePagesDir) + 1);
            $canonicalPage = "{$root}/Pages/{$relativePage}";

            expect(is_file($canonicalPage))
                ->toBeTrue("Theme '{$theme}' page override 'Pages/{$relativePage}' has no canonical counterpart at resources/js/Pages/{$relativePage}.");
        }
    }
});
