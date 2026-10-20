<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src'));
$failed = false;

foreach ($iterator as $file) {
    if (! $file->isFile() || 'php' !== strtolower($file->getExtension())) {
        continue;
    }
    $path = $file->getPathname();
    $output = [];
    $status = 0;
    exec(PHP_BINARY . ' -l ' . escapeshellarg($path), $output, $status);
    if (0 !== $status) {
        $failed = true;
        fwrite(STDERR, implode("\n", $output) . "\n");
    }
}

if ($failed) {
    exit(1);
}

echo "PASS: PHP syntax\n";
