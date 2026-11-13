<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SiteManager\Adapters\WordPress\ThemePackageInstallEngine;
use SiteManager\Adapters\WordPress\ThemePackageTreeFingerprint;
use SiteManager\Adapters\WordPress\ThemePackageBackupStore;

$GLOBALS['theme_engine_options'] = [];
$GLOBALS['theme_engine_environment'] = 'staging';
$GLOBALS['theme_engine_permission'] = true;
$GLOBALS['theme_engine_fs'] = 'direct';
$GLOBALS['theme_engine_theme_slug'] = 'client-theme';
$GLOBALS['theme_engine_root'] = '';
function get_stylesheet(): string { return $GLOBALS['theme_engine_theme_slug']; }
function get_template(): string { return $GLOBALS['theme_engine_theme_slug']; }
function get_stylesheet_directory(): string { return $GLOBALS['theme_engine_root']; }
function is_multisite(): bool { return false; }
function wp_get_environment_type(): string { return $GLOBALS['theme_engine_environment']; }
function wp_is_file_mod_allowed(string $context): bool { return $context === 'theme_package_deploy'; }
function get_filesystem_method(): string { return $GLOBALS['theme_engine_fs']; }
function current_user_can(string $capability): bool {
    return $GLOBALS['theme_engine_permission'] && in_array($capability,
        ['manage_options', 'install_themes', 'update_themes'], true);
}
function get_option(string $name, mixed $default = false): mixed {
    return $GLOBALS['theme_engine_options'][$name] ?? $default;
}
function add_option(string $name, mixed $value, mixed $deprecated = '', mixed $autoload = null): bool {
    if (array_key_exists($name, $GLOBALS['theme_engine_options'])) return false;
    $GLOBALS['theme_engine_options'][$name] = $value;
    return true;
}
function delete_option(string $name): bool {
    unset($GLOBALS['theme_engine_options'][$name]);
    return true;
}

$assert = static function (bool $ok, string $reason): void {
    if (! $ok) {
        fwrite(STDERR, "FAIL: $reason\n");
        exit(1);
    }
};
$reject = static function (callable $fn, string $code) use ($assert): void {
    try {
        $fn();
    } catch (DomainException $error) {
        $assert($error->getMessage() === $code, "Expected $code, got " . $error->getMessage());
        return;
    }
    $assert(false, "Expected $code");
};
$assert(class_exists(ZipArchive::class), 'ZipArchive required');

$dir = sys_get_temp_dir() . '/site-manager-upgrade-' . bin2hex(random_bytes(8));
$slug = 'client-theme';
$theme = "$dir/$slug";
$assert(mkdir($theme . '/assets/css', 0700, true), 'fixture theme root');
$GLOBALS['theme_engine_root'] = $theme;
$runtimePath = 'inc/seo-geo-core/src/Runtime.php';
$runtime = "<?php\n// fixed runtime\n";
$runtimeFiles = [$runtimePath=>hash('sha256', $runtime)];
$manifest = [
    'schema_version'=>1,
    'mode'=>'seo-geo-theme-release-integrity',
    'runtime_root'=>'inc/seo-geo-core',
    'runtime_file_count'=>1,
    'runtime_tree_sha256'=>hash('sha256', json_encode($runtimeFiles, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
    'runtime_files'=>$runtimeFiles,
    'theme_slug'=>$slug,
    'theme_version'=>'0.1.1',
    'release_channel'=>'prestable',
];
$build = static function (string $label, string $css) use ($dir, $slug, $runtime, $runtimePath, $manifest, $assert): array {
    $files = [
        'style.css'=>"/*\nTheme Name: Client Test Theme\nVersion: 0.1.1\n*/\n",
        'functions.php'=>"<?php\n// fixture\n",
        'assets/css/brand.css'=>$css,
        $runtimePath=>$runtime,
        'release-integrity.json'=>json_encode($manifest, JSON_THROW_ON_ERROR),
    ];
    $path = "$dir/$label.zip";
    $zip = new ZipArchive();
    $assert($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'open ZIP fixture');
    foreach ($files as $file=>$value) {
        $assert($zip->addFromString("$slug/$file", $value), 'write ZIP entry');
    }
    $zip->close();
    return ['path'=>$path,'sha'=>hash_file('sha256', $path),'files'=>$files];
};
$old = $build('old', 'h1{color:#000}');
$new = $build('new', 'h1{color:#abc}');
$writeTree = static function (array $files) use ($theme, $assert): void {
    foreach ($files as $name=>$value) {
        $path = "$theme/$name";
        if (! is_dir(dirname($path))) {
            $assert(mkdir(dirname($path), 0700, true), 'create fixture directory');
        }
        $assert(file_put_contents($path, $value) === strlen($value), 'write Theme fixture');
    }
};
$wipe = static function () use ($theme): void {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($theme, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir()) { rmdir($entry->getPathname()); }
        else { unlink($entry->getPathname()); }
    }
};
$writeTree($old['files']);
$tree = new ThemePackageTreeFingerprint();
$before = $tree->installed($theme, $slug)['tree_sha256'];
$after = $tree->archive($new['path'], $slug, $new['sha'])['tree_sha256'];
$assert($before !== $after, 'distinct CSS versions despite same WordPress Version header');

$mode = 'success';
$calls = 0;
$installer = static function (string $zipFile) use ($dir, $theme, $wipe, &$mode, &$calls): bool {
    ++$calls;
    if ($mode === 'always_fail') return false;
    $wipe();
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) return false;
    $success = $zip->extractTo($dir);
    $zip->close();
    if ($mode === 'partial_once') {
        $mode = 'success';
        return false;
    }
    return $success;
};
$engine = new ThemePackageInstallEngine($installer);
$call = static fn(bool $confirmed = true) => $engine->apply(
    $new['path'], $slug, $new['sha'], $before, $after, $confirmed
);
$reject(static fn() => $call(false), 'theme_package_confirmation_required');
$reject(static fn() => $engine->apply($new['path'], $slug, str_repeat('0', 64), $before, $after, true),
    'package_digest_mismatch');
$reject(static fn() => $engine->apply($new['path'], 'another-theme', $new['sha'], $before, $after, true),
    'theme_package_not_active_or_child');
$reject(static fn() => $engine->apply($new['path'], $slug, $new['sha'], str_repeat('0', 64), $after, true),
    'stale_installed_theme');

$GLOBALS['theme_engine_environment'] = 'production';
$reject(static fn() => $call(), 'theme_package_staging_only');
$GLOBALS['theme_engine_environment'] = 'staging';
$GLOBALS['theme_engine_permission'] = false;
$reject(static fn() => $call(), 'theme_package_permissions_denied');
$GLOBALS['theme_engine_permission'] = true;
$GLOBALS['theme_engine_fs'] = 'ftpext';
$reject(static fn() => $call(), 'theme_package_direct_filesystem_required');
$GLOBALS['theme_engine_fs'] = 'direct';
$lockName = 'site_manager_theme_package_write_lock';
$GLOBALS['theme_engine_options'][$lockName] = 'other-operation';
$reject(static fn() => $call(), 'theme_package_operation_in_progress');
unset($GLOBALS['theme_engine_options'][$lockName]);

$ok = $call();
$assert($ok['status'] === 'verified' && $ok['verified'] === true, 'successful WordPress install');
$assert($tree->installed($theme, $slug)['tree_sha256'] === $after, 'verify actual installed bytes');
$assert(isset($ok['backup']) && is_file($ok['backup']['path']), 'retain private backup for rollback');
$assert(! array_key_exists($lockName, $GLOBALS['theme_engine_options']), 'release lock on success');
$assert($calls === 1, 'one install call');

$reject(static fn() => $engine->rollback($ok['backup'], $slug, str_repeat('1', 64), true),
    'stale_installed_theme');
$restore = $engine->rollback($ok['backup'], $slug, $after, true);
$assert($restore['status'] === 'rolled_back' && $restore['verified'], 'explicit rollback succeeded');
$assert($tree->installed($theme, $slug)['tree_sha256'] === $before, 'original bytes restored');
$assert(! is_file($ok['backup']['path']), 'completed rollback releases private backup');

$mode = 'partial_once';
$partial = $call();
$assert($partial['status'] === 'rolled_back' && $partial['verified'] === false
    && $partial['recovered'] === true, 'failed upgrade triggers auto restoration');
$assert($tree->installed($theme, $slug)['tree_sha256'] === $before, 'auto rollback restored original bytes');
$assert(! array_key_exists($lockName, $GLOBALS['theme_engine_options']), 'lock released after failure');

$mode = 'always_fail';
$manual = $call();
$assert($manual['status'] === 'manual_recovery_required' && $manual['verified'] === false
    && $manual['recovered'] === false, 'failed recovery is not certified');
$assert(is_file($manual['backup']['path']), 'retain recovery package on unresolved failure');
(new ThemePackageBackupStore())->deleteRetained($manual['backup']['path']);
$assert(! array_key_exists($lockName, $GLOBALS['theme_engine_options']), 'lock released on incomplete recovery');

$wipe();
rmdir($theme);
unlink($old['path']);
unlink($new['path']);
rmdir($dir);
echo "WordPress Theme package executor contract: OK (approval, exact SHA, permissions, lock, apply, readback, rollback, auto-restore, manual recovery)\n";
