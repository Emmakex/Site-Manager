<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SiteManager\Adapters\WordPress\ThemePackageBackupStore;
use SiteManager\Adapters\WordPress\ThemePackageTreeFingerprint;

$assert = static function (bool $condition, string $reason): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: $reason\n");
        exit(1);
    }
};
$reject = static function (callable $operation, string $code) use ($assert): void {
    try {
        $operation();
    } catch (DomainException $e) {
        $assert($e->getMessage() === $code, "Expected $code, got " . $e->getMessage());
        return;
    }
    $assert(false, "Expected error $code");
};
if (! class_exists(ZipArchive::class)) {
    fwrite(STDERR, "FAIL: ext-zip is required\n");
    exit(1);
}
$dir = sys_get_temp_dir() . '/site-manager-backup-' . bin2hex(random_bytes(8));
$slug = 'client-theme';
$theme = "$dir/$slug";
$assert(mkdir($theme . '/assets/css', 0700, true), 'create fixture');
$files = [
    'style.css'=>"/*\nTheme Name: Demo WordPress Theme\nVersion: 0.1.1\n*/\n",
    'functions.php'=>"<?php\n// fixture only\n",
    'assets/css/corporate.css'=>"h1 { color: #135b63; }\n",
];
foreach ($files as $name=>$bytes) {
    $assert(file_put_contents($theme . '/' . $name, $bytes) === strlen($bytes), 'write fixture file');
}
$fingerprint = new ThemePackageTreeFingerprint();
$initial = $fingerprint->installed($theme, $slug);
$store = new ThemePackageBackupStore();
$backup = $store->capture($theme, $slug, $initial['tree_sha256']);
$assert(($backup['files'] ?? 0) === count($files), 'capture all files');
$assert(($backup['tree_sha256'] ?? null) === $initial['tree_sha256'], 'snapshot SHA bound');
$assert(is_file($backup['path']), 'temporary backup file exists');
$assert(strlen($backup['zip_sha256']) === 64, 'private ZIP has digest');
$restoration = $store->verifyRetained($backup['path'], $slug, $backup['zip_sha256'], $initial['tree_sha256']);
$assert($restoration['files'] === count($files), 'retained backup independently verified');

$reject(static fn() => $store->capture($theme, $slug, str_repeat('0', 64)), 'stale_installed_theme');
$reject(static fn() => $store->capture($theme, 'another-theme', $initial['tree_sha256']), 'invalid_installed_theme_path');
$reject(static fn() => $store->verifyRetained(
    $backup['path'], $slug, str_repeat('f', 64), $initial['tree_sha256']
), 'backup_archive_digest_mismatch');

$assert(file_put_contents($theme . '/assets/css/corporate.css', 'h1 { color: red; }') > 0, 'mutate active CSS');
$assert($fingerprint->installed($theme, $slug)['tree_sha256'] !== $initial['tree_sha256'], 'live CSS drift measured');
$assert($store->verifyRetained($backup['path'], $slug, $backup['zip_sha256'], $initial['tree_sha256'])['tree_sha256'] === $initial['tree_sha256'], 'backup survives live site drift');
$assert(file_put_contents($theme . '/assets/css/corporate.css', $files['assets/css/corporate.css']) > 0, 'restore live CSS');

$assert(symlink($backup['path'], $theme . '/unsafe'), 'create symlink to prove rejection');
$reject(static fn() => $store->capture($theme, $slug, $initial['tree_sha256']), 'unsafe_installed_theme_entry');
unlink($theme . '/unsafe');

$backupCopy = "$dir/copied.zip";
copy($backup['path'], $backupCopy);
$reject(static fn() => $store->verifyRetained(
    $backupCopy, $slug, hash_file('sha256', $backupCopy), $initial['tree_sha256']
), 'backup_not_private');
unlink($backupCopy);

$store->deleteRetained($backup['path']);
$assert(! is_file($backup['path']), 'safe completed backup deletion');
foreach (array_keys($files) as $name) {
    unlink($theme . '/' . $name);
}
rmdir($theme . '/assets/css');
rmdir($theme . '/assets');
rmdir($theme);
rmdir($dir);
echo "Private WordPress Theme backup contract: OK (verified snapshot, CSS drift, wrong identity, symlinks, public path, deletion)\n";
