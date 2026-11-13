<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SiteManager\Adapters\WordPress\ThemePackageArchiveGuard;

$assert = static function (bool $ok, string $message): void {
    if (! $ok) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
};
$reject = static function (callable $operation, string $expected) use ($assert): void {
    try {
        $operation();
    } catch (DomainException $e) {
        $assert($e->getMessage() === $expected, "Expected $expected; got " . $e->getMessage());
        return;
    }
    $assert(false, "Expected rejection: $expected");
};

if (! class_exists(ZipArchive::class)) {
    fwrite(STDERR, "FAIL: ext-zip is required by theme package preflight tests\n");
    exit(1);
}

$dir = sys_get_temp_dir() . '/site-manager-archive-' . bin2hex(random_bytes(8));
$assert(mkdir($dir, 0700), 'test fixture directory');
$slug = 'customer-theme';
$runtimeFile = 'inc/seo-geo-core/src/Runtime.php';
$runtimeContent = "<?php\n// safe test fixture\n";
$hashes = [$runtimeFile => hash('sha256', $runtimeContent)];
$manifest = [
    'schema_version'=>1,
    'mode'=>'seo-geo-theme-release-integrity',
    'runtime_root'=>'inc/seo-geo-core',
    'runtime_file_count'=>1,
    'runtime_tree_sha256'=>hash('sha256', json_encode($hashes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
    'runtime_files'=>$hashes,
    'theme_slug'=>$slug,
    'theme_version'=>'0.1.1',
    'release_channel'=>'prestable',
];
$valid = [
    "$slug/style.css"=>"/*\nTheme Name: Client Theme\nVersion: 0.1.1\n*/\n",
    "$slug/release-integrity.json"=>json_encode($manifest, JSON_THROW_ON_ERROR),
    "$slug/$runtimeFile"=>$runtimeContent,
    "$slug/functions.php"=>"<?php\n",
    "$slug/assets/css/cards.css"=>"p { color: black; }\n",
];
$archives = [];
$make = static function (string $name, array $files, ?callable $mutate = null) use ($dir, &$archives): string {
    $path = "$dir/$name.zip";
    $zip = new ZipArchive();
    if (true !== $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
        throw new RuntimeException('Cannot create acceptance fixture ZIP');
    }
    foreach ($files as $file=>$data) {
        if (! $zip->addFromString($file, $data)) {
            throw new RuntimeException('Cannot add acceptance fixture ZIP entry');
        }
    }
    if ($mutate !== null) {
        $mutate($zip);
    }
    $zip->close();
    $archives[] = $path;
    return $path;
};
$guard = new ThemePackageArchiveGuard();
$path = $make('valid', $valid);
$digest = hash_file('sha256', $path);
$proof = $guard->inspect($path, $slug, $digest);
$assert($proof['theme_slug'] === $slug, 'portable slug preserved');
$assert($proof['theme_version'] === '0.1.1', 'header version verified');
$assert($proof['package_sha256'] === $digest, 'package SHA-256 verified');
$assert($proof['manifest_verified'] === true && $proof['runtime_file_count'] === 1, 'runtime integrity verified');
$assert($proof['file_count'] === count($valid), 'entry count verified');
$assert($proof['stylesheet_sha256'] === hash('sha256', $valid["$slug/style.css"]), 'real stylesheet hash');

$reject(static fn() => $guard->inspect($path, $slug, str_repeat('0', 64)), 'package_digest_mismatch');
$reject(static fn() => $guard->inspect($path, 'other-theme', $digest), 'package_unsafe_path');
$reject(static fn() => $guard->inspect($path, '../customer-theme', $digest), 'invalid_package_identity');
$reject(static fn() => $guard->inspect($path, $slug, 'wrong-sha'), 'invalid_package_identity');
$reject(static fn() => $guard->inspect($dir . '/missing.zip', $slug, $digest), 'package_not_readable');

$traversal = $make('traversal', $valid + ["$slug/../../wp-config.php"=>"attack"]);
$reject(static fn() => $guard->inspect($traversal, $slug, hash_file('sha256', $traversal)), 'package_unsafe_path');
$absolute = $make('backslash', $valid + ["$slug/assets\\../evil.php"=>"attack"]);
$reject(static fn() => $guard->inspect($absolute, $slug, hash_file('sha256', $absolute)), 'package_unsafe_path');
$wrongRoot = $make('wrong-root', ['other-theme/style.css'=>$valid["$slug/style.css"]] + $valid);
$reject(static fn() => $guard->inspect($wrongRoot, $slug, hash_file('sha256', $wrongRoot)), 'package_unsafe_path');
$duplicate = $make('case-duplicate', $valid + ["$slug/assets/css/CARDS.CSS"=>'p { color: red; }']);
$reject(static fn() => $guard->inspect($duplicate, $slug, hash_file('sha256', $duplicate)), 'package_duplicate_entry');
$symlink = $make('symlink', $valid, static function (ZipArchive $zip) use ($slug): void {
    $name = "$slug/shortcut";
    $zip->addFromString($name, '../wp-config.php');
    $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, (0120777 << 16));
});
$reject(static fn() => $guard->inspect($symlink, $slug, hash_file('sha256', $symlink)), 'package_unsupported_file_type');

$tamperedRuntime = $valid;
$tamperedRuntime["$slug/$runtimeFile"] = '<?php echo "tampered";';
$tampered = $make('tampered-runtime', $tamperedRuntime);
$reject(static fn() => $guard->inspect($tampered, $slug, hash_file('sha256', $tampered)), 'package_runtime_digest_mismatch');
$invalidManifest = $valid;
$invalidManifest["$slug/release-integrity.json"] = '{"runtime_files": {}}';
$manifestBad = $make('bad-manifest', $invalidManifest);
$reject(static fn() => $guard->inspect($manifestBad, $slug, hash_file('sha256', $manifestBad)), 'package_invalid_release_manifest');
$huge = $make('zip-bomb', $valid + ["$slug/assets/css/long.css"=>str_repeat('x', 2_000_000)]);
$reject(static fn() => $guard->inspect($huge, $slug, hash_file('sha256', $huge)), 'package_expansion_limit');

foreach ($archives as $archive) {
    unlink($archive);
}
rmdir($dir);
echo "Theme immutable package preflight contract: OK (valid, bad digest, slug, path, duplicate, symlink, manifest, tamper, expansion)\n";
