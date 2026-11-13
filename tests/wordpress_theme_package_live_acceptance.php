<?php
declare(strict_types=1);

/**
 * End-to-end acceptance in an isolated, disposable WordPress + MariaDB.
 * This is an integration test, not a production recovery or install script.
 */
require '/var/www/html/wp-load.php';

use SiteManager\Adapters\WordPress\RemoteConnectionStore;
use SiteManager\Adapters\WordPress\ThemePackageTreeFingerprint;

$assert = static function (bool $ok, string $why): void {
    if (! $ok) {
        fwrite(STDERR, "FAIL: {$why}\n");
        exit(1);
    }
};
$assert(defined('ABSPATH'), 'real WordPress must be loaded');
$assert(function_exists('rest_do_request'), 'real WordPress REST dispatch present');
$assert(class_exists(RemoteConnectionStore::class), 'official Site Manager plugin must be active');
$assert(wp_get_environment_type() === 'staging', 'disposable WP must run in staging mode');
$assert((string) get_option('blog_public') === '0', 'nonindexable disposable WordPress baseline');

$admin = get_user_by('login','testadmin');
$assert($admin instanceof WP_User && $admin->ID > 0, 'real WordPress admin present');
wp_set_current_user($admin->ID);

$slug = 'seo-geo-theme';
$themeDir = WP_CONTENT_DIR . '/themes/' . $slug;
$runtimeFile = 'inc/seo-geo-core/src/Runtime.php';
$runtimeContent = "<?php\n// Integration test only. CI has no connection to Emmake.\n";
$hashes = [$runtimeFile=>hash('sha256',$runtimeContent)];
$manifest = [
    'schema_version'=>1,
    'mode'=>'seo-geo-theme-release-integrity',
    'runtime_root'=>'inc/seo-geo-core',
    'runtime_file_count'=>1,
    'runtime_tree_sha256'=>hash(
        'sha256', json_encode($hashes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
    ),
    'runtime_files'=>$hashes,
    'theme_slug'=>$slug,
    'theme_version'=>'0.1.1',
    'release_channel'=>'prestable',
];
$files = [
    'style.css'=>"/*\nTheme Name: Site Manager Disposable Theme\nVersion: 0.1.1\n*/\n",
    'index.php'=>"<?php\n// Real WP Theme acceptance index.\n",
    'functions.php'=>"<?php\n// Real WP Theme acceptance functions.\n",
    'assets/css/blocks.css'=>"body{color:#214e57}\n",
    $runtimeFile=>$runtimeContent,
    'release-integrity.json'=>json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
];
$assert(! is_dir($themeDir), 'test theme must not exist before fixture setup');
$assert(wp_mkdir_p($themeDir), 'create isolated WordPress theme');
foreach ($files as $relative=>$bytes) {
    $target = $themeDir . '/' . $relative;
    $assert(wp_mkdir_p(dirname($target)), 'create WordPress theme subdirectory');
    $assert(file_put_contents($target,$bytes) === strlen($bytes), 'write initial Theme fixture');
}
wp_clean_themes_cache(true);
$assert(wp_get_theme($slug)->exists(), 'WordPress detects fixture as valid Theme');
switch_theme($slug);
$assert(get_stylesheet() === $slug && get_template() === $slug, 'active standalone theme selected');

$contentId = wp_insert_post([
    'post_type'=>'post',
    'post_status'=>'draft',
    'post_title'=>'Disposable Site Manager content protection',
    'post_content'=>'WordPress content must remain unchanged by Theme package deployment.',
], true);
$assert(is_int($contentId) && $contentId > 0, 'real WordPress content fixture created');
$initialContent = (string) get_post_field('post_content',$contentId);
$initialPublic = (string) get_option('blog_public');
$initialPosts = (int) wp_count_posts('post')->draft;

$tree = new ThemePackageTreeFingerprint();
$oldSha = (string) $tree->installed($themeDir,$slug)['tree_sha256'];

$newFiles = $files;
$newFiles['assets/css/blocks.css'] = "body{color:#445d90}\n";
$zipPath = sys_get_temp_dir() . '/sm-disposable-theme-' . bin2hex(random_bytes(8)) . '.zip';
$zip = new ZipArchive();
$assert(true === $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE), 'real release ZIP writable');
foreach ($newFiles as $relative=>$bytes) {
    $assert($zip->addFromString($slug . '/' . $relative, $bytes), 'write trusted CI-like fixture bytes');
}
$assert($zip->close(), 'release ZIP committed');
$newSha = (string) $tree->archive($zipPath,$slug,hash_file('sha256',$zipPath))['tree_sha256'];
$assert($oldSha !== $newSha, 'new CSS changes bytes but not nominal Theme version');

$tokenData = (new RemoteConnectionStore())->issue(
    ['site.read','theme.package.write'], $admin->ID
);
$token = (string) ($tokenData['token'] ?? '');
$assert(strlen($token) === 64, 'real WordPress scoped connection created');

$call = static function (string $stage, array $params, string $auth) use ($assert): WP_REST_Response {
    $request = new WP_REST_Request('POST','/site-manager/v1/write/theme/package/' . $stage);
    $request->set_header('content-type','application/json');
    $request->set_header('authorization', $auth === '' ? '' : 'Bearer ' . $auth);
    $request->set_body(wp_json_encode($params));
    $result = rest_do_request($request);
    $assert($result instanceof WP_REST_Response, 'WordPress REST returns a structured response');
    return $result;
};
$planFields = [
    'theme_slug'=>$slug,
    'package_sha256'=>hash_file('sha256',$zipPath),
    'package_base64'=>base64_encode((string) file_get_contents($zipPath)),
    'source_commit_sha'=>str_repeat('a',40),
    'source_run_id'=>123,
    'source_artifact_id'=>456,
    'reason'=>'Disposable WordPress same-version CSS installer acceptance',
];

$unauthorized = $call('plan',$planFields,'');
$assert($unauthorized->get_status() >= 400, 'code write without Bearer is denied');

$badSha = $planFields;
$badSha['package_sha256'] = str_repeat('f',64);
$rejected = $call('plan',$badSha,$token);
$assert($rejected->get_status() === 409, 'wrong ZIP digest rejected before WordPress write');
$assert($tree->installed($themeDir,$slug)['tree_sha256'] === $oldSha, 'bad release did not modify Theme');

$plan = $call('plan',$planFields,$token);
$assert($plan->get_status() === 200, 'real WordPress package plan succeeded');
$planned = $plan->get_data()['data'] ?? [];
$assert(($planned['status'] ?? '') === 'planned', 'WordPress plan state returned');
$assert(($planned['preview']['after_tree_sha256'] ?? '') === $newSha, 'official ZIP tree preview equals independently built release');
$assert(($planned['preview']['before_tree_sha256'] ?? '') === $oldSha, 'previous installed Theme SHA read back');
$planId = (string) ($planned['plan']['plan_id'] ?? '');
$assert(preg_match('/^sm-plan-[a-f0-9]{24}$/D', $planId) === 1, 'WordPress generated opaque plan ID');
$assert(strpos(wp_json_encode($planned),$zipPath) === false, 'WordPress plan never leaks package path');
$assert(strpos(wp_json_encode($planned), 'package_base64') === false, 'WordPress plan never leaks archive Base64');

$noApproval = $call('apply',['plan_id'=>$planId,'confirmed'=>false],$token);
$assert($noApproval->get_status() === 409, 'apply requires explicit approval');
$assert($tree->installed($themeDir,$slug)['tree_sha256'] === $oldSha, 'unapproved plan cannot mutate files');

ob_start();
$applied = $call('apply',['plan_id'=>$planId,'confirmed'=>true],$token);
ob_end_clean();
if ($applied->get_status() !== 200) {
    fwrite(STDERR,"WORDPRESS_APPLY_STATUS=".$applied->get_status()." code=".(
        $applied->get_data()['error']['code'] ?? $applied->get_data()['data']['status'] ?? 'unknown'
    )."\n");
}
$assert($applied->get_status() === 200, 'actual Theme_Upgrader apply succeeded');
$applyBody = $applied->get_data()['data'] ?? [];
$assert(($applyBody['status'] ?? null) === 'verified' && ($applyBody['verified'] ?? false) === true,
    'WordPress full-tree apply is verified');
$assert($tree->installed($themeDir,$slug)['tree_sha256'] === $newSha, 'new CSS installed at expected exact bytes');
$assert(get_stylesheet() === $slug, 'active WP Theme identity did not change');
$assert((string) get_post_field('post_content',$contentId) === $initialContent,
    'real WordPress post body survived upgrade');
$assert((string) get_option('blog_public') === $initialPublic, 'noindex preserved through upgrade');
$assert((int) wp_count_posts('post')->draft === $initialPosts, 'WordPress post count preserved');

ob_start();
$restored = $call('rollback',['plan_id'=>$planId,'confirmed'=>true],$token);
ob_end_clean();
if ($restored->get_status() !== 200) {
    fwrite(STDERR,"WORDPRESS_ROLLBACK_STATUS=".$restored->get_status()." code=".(
        $restored->get_data()['error']['code'] ?? $restored->get_data()['data']['status'] ?? 'unknown'
    )."\n");
}
$assert($restored->get_status() === 200, 'WordPress native Theme rollback succeeded');
$rollbackBody = $restored->get_data()['data'] ?? [];
$assert(($rollbackBody['status'] ?? null) === 'rolled_back' && ($rollbackBody['verified'] ?? false) === true,
    'WordPress rollback verified in original Theme filesystem');
$assert($tree->installed($themeDir,$slug)['tree_sha256'] === $oldSha,
    'original live Theme CSS exact SHA restored by WordPress');
$assert((string) get_post_field('post_content',$contentId) === $initialContent, 'content survived rollback');
$assert((string) get_option('blog_public') === $initialPublic, 'noindex survived rollback');
$assert(get_stylesheet() === $slug, 'theme activation unchanged after rollback');

$replay = $call('rollback',['plan_id'=>$planId,'confirmed'=>true],$token);
$assert($replay->get_status() === 409, 'same rollback plan cannot be replayed');
@unlink($zipPath);
echo "DISPOSABLE_WP_THEME_PACKAGE_ACCEPTANCE=PASSED\n";
echo "BACKUP_RESTORED_TREE_SHA256=".$oldSha."\n";
echo "RELEASE_INSTALLED_TREE_SHA256=".$newSha."\n";
echo "CONTENT_AND_NOINDEX_PRESERVED=true\n";
