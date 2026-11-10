<?php
declare(strict_types=1);

$baseDir = dirname(__DIR__);
$nodeDir = $baseDir . DIRECTORY_SEPARATOR . '.node-bin';
$isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

$os = $isWindows ? 'win' : (PHP_OS_FAMILY === 'Darwin' ? 'darwin' : 'linux');
$arch = php_uname('m');
$archMap = [
    'x86_64'  => 'x64',
    'amd64'   => 'x64',
    'aarch64' => 'arm64',
    'arm64'   => 'arm64',
    'armv7l'  => 'armv7l',
    'ppc64le' => 'ppc64le',
    's390x'   => 's390x',
];
$nodeArch = $archMap[$arch] ?? 'x64';

$nodeBinary = $nodeDir . DIRECTORY_SEPARATOR . ($isWindows ? 'node.exe' : 'bin/node');
$npmBinary  = $nodeDir . DIRECTORY_SEPARATOR . ($isWindows ? 'npm.cmd' : 'bin/npm');

if (!file_exists($nodeBinary)) {
    echo ">> [LiteControl] Isolated Node 24 not found. Checking latest Node v24.x release for {$os}-{$nodeArch}...\n";

    $context = stream_context_create([
        'http' => [
            'timeout' => 30,
            'header'  => "User-Agent: LiteControl-Setup\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ]
    ]);

    $shasums = @file_get_contents('https://nodejs.org/dist/latest-v24.x/SHASUMS256.txt', false, $context);
    if ($shasums === false) {
        fwrite(STDERR, "Error: Could not reach nodejs.org to query Node 24 distribution index.\n");
        exit(1);
    }

    $ext = $isWindows ? 'zip' : 'tar.xz';
    $pattern = '/node-(v24\.[0-9]+\.[0-9]+)-' . preg_quote("{$os}-{$nodeArch}", '/') . '\.' . preg_quote($ext, '/') . '/';

    if (!preg_match($pattern, $shasums, $matches)) {
        // Fallback to tar.gz if tar.xz pattern is not found
        $ext = $isWindows ? 'zip' : 'tar.gz';
        $pattern = '/node-(v24\.[0-9]+\.[0-9]+)-' . preg_quote("{$os}-{$nodeArch}", '/') . '\.' . preg_quote($ext, '/') . '/';
        if (!preg_match($pattern, $shasums, $matches)) {
            fwrite(STDERR, "Error: Could not find matching Node 24 archive for {$os}-{$nodeArch} in official index.\n");
            exit(1);
        }
    }

    $archiveName = $matches[0];
    $downloadUrl = "https://nodejs.org/dist/latest-v24.x/{$archiveName}";
    $tempArchive = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $archiveName;

    echo ">> Downloading isolated {$archiveName} from nodejs.org...\n";
    $fp = @fopen($downloadUrl, 'rb', false, $context);
    if (!$fp) {
        fwrite(STDERR, "Error: Failed to open download stream for {$downloadUrl}.\n");
        exit(1);
    }

    $dest = @fopen($tempArchive, 'wb');
    if (!$dest) {
        fclose($fp);
        fwrite(STDERR, "Error: Unable to open temporary destination file: {$tempArchive}\n");
        exit(1);
    }

    stream_copy_to_stream($fp, $dest);
    fclose($fp);
    fclose($dest);

    if (!is_dir($nodeDir)) {
        mkdir($nodeDir, 0755, true);
    }

    echo ">> Extracting {$archiveName} to {$nodeDir}...\n";

    if ($isWindows) {
        $zip = new ZipArchive();
        if ($zip->open($tempArchive) === true) {
            $tempExtractDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'node_extract_' . uniqid();
            @mkdir($tempExtractDir, 0755, true);
            $zip->extractTo($tempExtractDir);
            $zip->close();

            $extractedDirs = glob($tempExtractDir . DIRECTORY_SEPARATOR . 'node-v24*');
            $sourceDir = !empty($extractedDirs) ? $extractedDirs[0] : $tempExtractDir;

            foreach (scandir($sourceDir) as $item) {
                if ($item !== '.' && $item !== '..') {
                    $src = $sourceDir . DIRECTORY_SEPARATOR . $item;
                    $dst = $nodeDir . DIRECTORY_SEPARATOR . $item;
                    if (is_dir($src)) {
                        // Simple recursive copy
                        $iterator = new RecursiveIteratorIterator(
                            new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
                            RecursiveIteratorIterator::SELF_FIRST
                        );
                        foreach ($iterator as $f) {
                            $targetPath = $dst . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
                            if ($f->isDir()) {
                                @mkdir($targetPath, 0755, true);
                            } else {
                                copy($f->getPathname(), $targetPath);
                            }
                        }
                    } else {
                        copy($src, $dst);
                    }
                }
            }
            // Cleanup temp
            exec("rmdir /s /q " . escapeshellarg($tempExtractDir) . " 2>nul");
        } else {
            fwrite(STDERR, "Error: Failed to open zip archive {$tempArchive}.\n");
            @unlink($tempArchive);
            exit(1);
        }
    } else {
        // Linux / Unix extraction preserving symlinks and permissions
        $cmd = "tar -xf " . escapeshellarg($tempArchive) . " -C " . escapeshellarg($nodeDir) . " --strip-components=1";
        exec($cmd, $out, $ret);
        if ($ret !== 0) {
            fwrite(STDERR, "Error: Extraction failed with exit code {$ret}: " . implode("\n", $out) . "\n");
            @unlink($tempArchive);
            exit(1);
        }
    }

    @unlink($tempArchive);

    if (!file_exists($nodeBinary)) {
        fwrite(STDERR, "Error: Binary not found after extraction at {$nodeBinary}.\n");
        exit(1);
    }

    if (!$isWindows) {
        chmod($nodeBinary, 0755);
        if (file_exists($npmBinary)) {
            chmod($npmBinary, 0755);
        }
    }

    $installedVersion = trim(shell_exec(escapeshellarg($nodeBinary) . " -v") ?? '');
    echo ">> Isolated Node installed: {$installedVersion} at {$nodeBinary}\n";
}

// Prepend isolated node/npm directory to PATH for child processes
$nodeBinDir = $isWindows ? $nodeDir : ($nodeDir . DIRECTORY_SEPARATOR . 'bin');
$currentPath = getenv('PATH') ?: '';
putenv("PATH={$nodeBinDir}" . PATH_SEPARATOR . $currentPath);
$_ENV['PATH'] = "{$nodeBinDir}" . PATH_SEPARATOR . $currentPath;

// Configure npm cache directory inside isolated node dir or local writable dir to avoid root-owned ~/.npm permission issues
$npmCacheDir = $nodeDir . DIRECTORY_SEPARATOR . '.npm-cache';
if (!is_dir($npmCacheDir)) {
    @mkdir($npmCacheDir, 0755, true);
}
putenv("npm_config_cache={$npmCacheDir}");
$_ENV['npm_config_cache'] = $npmCacheDir;

$cacheFlag = " --cache=" . escapeshellarg($npmCacheDir);

// Run npm install
echo ">> Running npm install via isolated Node/npm...\n";
$procInstall = proc_open(
    escapeshellarg($npmBinary) . " install --include=optional --no-audit --no-fund" . $cacheFlag,
    [0 => STDIN, 1 => STDOUT, 2 => STDERR],
    $pipes,
    $baseDir
);

$retInstall = proc_close($procInstall);
if ($retInstall !== 0) {
    fwrite(STDERR, "Error: npm install failed with exit code {$retInstall}.\n");
    exit($retInstall);
}

// Run npm run build
echo ">> Running npm run build via isolated Node/npm...\n";
$procBuild = proc_open(
    escapeshellarg($npmBinary) . " run build",
    [0 => STDIN, 1 => STDOUT, 2 => STDERR],
    $pipes,
    $baseDir
);

$retBuild = proc_close($procBuild);
if ($retBuild !== 0) {
    fwrite(STDERR, "Error: npm run build failed with exit code {$retBuild}.\n");
    exit($retBuild);
}

echo ">> [LiteControl] Isolated frontend build complete.\n";

