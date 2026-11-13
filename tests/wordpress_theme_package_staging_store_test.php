<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use SiteManager\Adapters\WordPress\ThemePackageStagingStore;

$assert = static function (bool $truth, string $why): void {
    if (! $truth) { fwrite(STDERR, "FAIL: $why\n"); exit(1); }
};
$reject = static function (callable $fn, string $code) use ($assert): void {
    try { $fn(); }
    catch (DomainException $e) {
        $assert($e->getMessage() === $code, "Expected $code, got " . $e->getMessage());
        return;
    }
    $assert(false, "Expected $code");
};
$assert(class_exists(ZipArchive::class), 'ext-zip required');

$slug = 'unrelated-theme';
$runtime = 'inc/seo-geo-core/src/Runtime.php';
$bytes = '<?php // test fixture';
$map = [$runtime=>hash('sha256', $bytes)];
$manifest = [
    'schema_version'=>1,
    'mode'=>'seo-geo-theme-release-integrity',
    'runtime_root'=>'inc/seo-geo-core',
    'runtime_file_count'=>1,
    'runtime_tree_sha256'=>hash('sha256', json_encode($map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
    'runtime_files'=>$map,
    'theme_slug'=>$slug,
    'theme_version'=>'0.1.1',
    'release_channel'=>'prestable',
];
$zipPath = tempnam(sys_get_temp_dir(), 'sm-release-fixture-');
$zip = new ZipArchive();
$assert($zip->open($zipPath, ZipArchive::OVERWRITE) === true, 'open fixture');
foreach ([
    "$slug/style.css"=>"/*\nTheme Name: Fixture\nVersion: 0.1.1\n*/\n",
    "$slug/functions.php"=>"<?php\n",
    "$slug/$runtime"=>$bytes,
    "$slug/release-integrity.json"=>json_encode($manifest, JSON_THROW_ON_ERROR),
] as $path=>$data) {
    $assert($zip->addFromString($path, $data), 'archive fixture entry');
}
$zip->close();
$package = file_get_contents($zipPath);
$sha = hash('sha256', $package);
unlink($zipPath);
$stage = new ThemePackageStagingStore();
$record = $stage->stage($package, $slug, $sha);
$assert($record['manifest_verified'] === true, 'verified official-style manifest');
$assert($record['package_sha256'] === $sha, 'digest retained');
$assert($record['theme_slug'] === $slug, 'slug retained');
$assert($record['file_count'] === 4, 'all files counted');
$assert(is_file($record['path']), 'private staged ZIP exists');
$assert($stage->verify($record['path'], $slug, $sha)['tree_sha256'] === $record['tree_sha256'], 'independent readback');

$reject(static fn() => $stage->stage($package, $slug, str_repeat('0', 64)), 'package_digest_mismatch');
$reject(static fn() => $stage->stage($package, 'different-theme', $sha), 'package_unsafe_path');
$reject(static fn() => $stage->verify('/tmp/other-supplied-file.zip', $slug, $sha), 'release_stage_not_private');

$original = file_get_contents($record['path']);
$assert(file_put_contents($record['path'], $original . 'changed') > 0, 'tamper test fixture');
$reject(static fn() => $stage->verify($record['path'], $slug, $sha), 'package_digest_mismatch');
$assert(file_put_contents($record['path'], $original) > 0, 'restore stage bytes');
$stage->release($record['path']);
$assert(! is_file($record['path']) && ! is_dir(dirname($record['path'])), 'private stage cleaned up');
echo "Theme package staging: OK (private storage, signature, tamper, wrong theme, cleanup)\n";
