<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SiteManager\Adapters\WordPress\ThemePackageTreeFingerprint;

$assert = static function (bool $condition, string $reason): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: $reason\n");
        exit(1);
    }
};
$reject = static function (callable $action, string $code) use ($assert): void {
    try {
        $action();
    } catch (DomainException $e) {
        $assert($e->getMessage() === $code, "Unexpected error: " . $e->getMessage());
        return;
    }
    $assert(false, "Expected $code");
};
$assert(class_exists(ZipArchive::class), 'ZipArchive extension required');

$slug = 'custom-theme';
$dir = sys_get_temp_dir() . '/sm-theme-tree-' . bin2hex(random_bytes(8));
$root = $dir . '/' . $slug;
$assert(mkdir($root, 0700, true), 'create isolated theme directory');
$files = [
    'style.css'=>"/*\nTheme Name: Testing Theme\nVersion: 0.1.1\n*/\n",
    'functions.php'=>"<?php\n",
    'assets/css/cards.css'=>'p{color:teal}',
    'inc/seo-geo-core/src/Runtime.php'=>"<?php\n// test runtime\n",
];
$runtimeKey = 'inc/seo-geo-core/src/Runtime.php';
$runtimeFiles = [$runtimeKey=>hash('sha256', $files[$runtimeKey])];
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
$files['release-integrity.json'] = json_encode($manifest, JSON_THROW_ON_ERROR);
foreach ($files as $file=>$bytes) {
    $target = $root . '/' . $file;
    $parent = dirname($target);
    if (! is_dir($parent)) {
        $assert(mkdir($parent, 0700, true), 'create test fixture directory');
    }
    $assert(file_put_contents($target, $bytes) === strlen($bytes), 'write test file');
}

$archivePath = $dir . '/official.zip';
$zip = new ZipArchive();
$assert($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'create test ZIP');
foreach ($files as $file=>$bytes) {
    $assert($zip->addFromString("$slug/$file", $bytes), 'add fixture');
}
$zip->close();
$zipSha = hash_file('sha256', $archivePath);
$fp = new ThemePackageTreeFingerprint();
$expected = $fp->archive($archivePath, $slug, $zipSha);
$actual = $fp->installed($root, $slug);
$assert($expected === $actual, 'archive and actual installed tree match byte for byte');
$verified = $fp->compare($archivePath, $root, $slug, $zipSha);
$assert($verified['verified'] === true, 'initial installed readback must verify');
$assert($verified['expected_files'] === count($files), 'count all theme files including manifest');

$cssPath = $root . '/assets/css/cards.css';
$assert(file_put_contents($cssPath, 'p{color:red}') > 0, 'change only installed CSS');
$drift = $fp->compare($archivePath, $root, $slug, $zipSha);
$assert($drift['verified'] === false, 'CSS byte drift must fail even when Version is unchanged');
$assert($drift['expected_files'] === $drift['installed_files'], 'file count alone does not prove identity');
$assert(file_put_contents($cssPath, $files['assets/css/cards.css']) > 0, 'restore fixture CSS');

$assert(file_put_contents($root . '/unapproved.php', '<?php ?>') > 0, 'create unexpected file');
$drift = $fp->compare($archivePath, $root, $slug, $zipSha);
$assert($drift['verified'] === false && $drift['installed_files'] === count($files) + 1, 'extra theme file must fail');
unlink($root . '/unapproved.php');
$assert($fp->compare($archivePath, $root, $slug, $zipSha)['verified'], 'restored tree verifies again');

$reject(static fn() => $fp->archive($archivePath, $slug, str_repeat('f', 64)), 'package_digest_mismatch');
$reject(static fn() => $fp->installed($root, 'other-theme'), 'invalid_installed_theme_path');
$assert(symlink($dir . '/official.zip', $root . '/unsafe-link'), 'create test symlink');
$reject(static fn() => $fp->installed($root, $slug), 'unsafe_installed_theme_entry');
unlink($root . '/unsafe-link');

foreach (array_reverse(array_keys($files)) as $name) {
    unlink($root . '/' . $name);
}
rmdir($root . '/assets/css');
rmdir($root . '/assets');
rmdir($root . '/inc/seo-geo-core/src');
rmdir($root . '/inc/seo-geo-core');
rmdir($root . '/inc');
rmdir($root);
unlink($archivePath);
rmdir($dir);
echo "Installed Theme full-tree byte verification: OK (match, CSS drift, extra file, wrong slug, symlink, digest)\n";
