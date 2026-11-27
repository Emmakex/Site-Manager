<?php
declare(strict_types=1);

/** Disposable WP only: simulate a broken replacement and restore from separate code. */
$stage=$argv[1] ?? '';
if ($stage==='restore') {
    // Pre-bootstrap WP hook, like a skip-plugins maintenance process. It
    // suppresses active plugin loading only for this process, not the database.
    $GLOBALS['wp_filter']['pre_option_active_plugins'][10][]=[
        'function'=>static fn()=>[], 'accepted_args'=>0,
    ];
}
require '/var/www/html/wp-load.php';

use SiteManager\Adapters\WordPress\PluginPackageArchiveGuard;
use SiteManager\Adapters\WordPress\PluginPackageRecoveryStore;
use SiteManager\Adapters\WordPress\PluginPackageInstallEngine;

function check(bool $ok,string $label): void {
    if (! $ok) { fwrite(STDERR,"FAIL: ".$label."\n"); exit(1); }
}
function blocked(callable $fn): void {
    try { $fn(); } catch (DomainException) { return; }
    throw new RuntimeException('Unexpected mutation acceptance');
}
check(wp_get_environment_type()==='staging' && (string)get_option('blog_public')==='0','disposable WP only');
require_once ABSPATH.'wp-admin/includes/plugin.php';
$admin=get_user_by('login','testadmin');
check($admin instanceof WP_User,'fixture administrator');
wp_set_current_user($admin->ID);
$root=WP_PLUGIN_DIR.'/site-manager';
$journalKey='site_manager_self_update_journal';

if ($stage==='apply') {
    check(defined('SITE_MANAGER_VERSION') && SITE_MANAGER_VERSION==='0.12.3','original runtime loaded');
    check(! get_option($journalKey),'no prior journal');
    $store=new PluginPackageRecoveryStore();
    $before=$store->fingerprint($root);
    $zipPath=tempnam(get_temp_dir(),'sm-broken-test-');
    check(is_string($zipPath),'private test archive');
    $zip=new ZipArchive();
    check($zip->open($zipPath,ZipArchive::OVERWRITE)===true,'candidate zip');
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $entry) {
        if (! $entry->isFile()) { continue; }
        $relative=substr($entry->getPathname(),strlen($root)+1);
        $bytes=file_get_contents($entry->getPathname());
        if ($relative==='site-manager.php') {
            $bytes=str_replace('0.12.3','0.12.4',$bytes);
            $needle="if (! defined('ABSPATH')) { exit; }";
            check(str_contains($bytes,$needle),'fixture bootstrap marker');
            $bytes=str_replace($needle,$needle."\nthrow new RuntimeException('DISPOSABLE_PLUGIN_FATAL_FIXTURE');",$bytes);
        }
        check($zip->addFromString('site-manager/'.$relative,$bytes),'candidate entry');
    }
    check($zip->close(),'candidate finalized');
    $sha=hash_file('sha256',$zipPath);
    $proof=(new PluginPackageArchiveGuard())->inspect($zipPath,'0.12.4',$sha);
    $engine=new PluginPackageInstallEngine();
    $active=get_option('active_plugins');
    $content=wp_insert_post(['post_type'=>'post','post_status'=>'draft','post_title'=>'Self-update fixture','post_content'=>'Preserve me'],true);
    check(is_int($content) && $content>0,'content fixture');
    update_option('site_manager_disposable_self_update_baseline',['content'=>$content,'active'=>$active,'tree'=>$before['tree_sha256']],false);
    blocked(fn()=>$engine->apply($zipPath,'0.12.4',$sha,$before['tree_sha256'],$proof['tree_sha256'],false));
    blocked(fn()=>$engine->apply($zipPath,'0.12.4',$sha,str_repeat('0',64),$proof['tree_sha256'],true));
    check(! get_option($journalKey),'invalid attempts created no journal');
    $result=$engine->apply($zipPath,'0.12.4',$sha,$before['tree_sha256'],$proof['tree_sha256'],true);
    @unlink($zipPath);
    check($result['status']==='installed_pending_runtime' && $result['verified']===false && $result['tree_verified']===true,'installed but runtime NOT yet verified');
    check(get_option('active_plugins')===$active,'activation options preserved');
    echo "DISPOSABLE_WP_PLUGIN_INSTALL_TREE=PASSED\n";
} elseif ($stage==='restore') {
    check(! defined('SITE_MANAGER_VERSION'),'broken plugin did not load in independent recovery');
    remove_all_filters('pre_option_active_plugins');
    foreach (['PluginPackageArchiveGuard','PluginPackageRecoveryStore','PluginPackageInstallEngine'] as $class) {
        require '/tmp/sm-plugin-recovery-worker/'.$class.'.php';
    }
    $journal=get_option($journalKey);
    check(is_array($journal) && $journal['status']==='installed_pending_runtime','retained installation journal');
    $engine=new PluginPackageInstallEngine();
    blocked(fn()=>$engine->restore(str_repeat('f',32),true));
    blocked(fn()=>$engine->restore($journal['id'],false));
    $result=$engine->restore($journal['id'],true);
    check($result['status']==='restored_pending_runtime' && $result['verified']===false && $result['tree_verified']===true,'independent restore verified tree');
    echo "DISPOSABLE_WP_PLUGIN_INDEPENDENT_RESTORE=PASSED\n";
} elseif ($stage==='verify') {
    check(defined('SITE_MANAGER_VERSION') && SITE_MANAGER_VERSION==='0.12.3','fresh old runtime loaded');
    $baseline=get_option('site_manager_disposable_self_update_baseline');
    $journal=get_option($journalKey);
    check($journal['status']==='restored_pending_runtime','restored journal');
    check((new PluginPackageRecoveryStore())->fingerprint($root)['tree_sha256']===$baseline['tree'],'original exact tree restored');
    check(get_option('active_plugins')===$baseline['active'] && get_post_status($baseline['content'])==='draft'
        && get_post_field('post_content',$baseline['content'])==='Preserve me','content and active plugins unchanged');
    echo "DISPOSABLE_WP_PLUGIN_FRESH_RUNTIME_RESTORED=PASSED\n";
} else {
    throw new RuntimeException('Unknown fixture stage');
}
