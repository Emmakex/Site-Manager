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
$privateDirectory='/tmp/sm-plugin-recovery-worker/storage';
check(is_dir($privateDirectory),'fixed private recovery directory');

if (in_array($stage,['apply','apply-healthy'],true)) {
    check(defined('SITE_MANAGER_VERSION'),'original runtime loaded');
    $original=SITE_MANAGER_VERSION;
    $parts=explode('.',$original);
    $candidate=$parts[0].'.'.$parts[1].'.'.((int)$parts[2]+1);
    $broken=$stage==='apply';
    $connection=new \SiteManager\Adapters\WordPress\RemoteConnectionStore();
    if ($broken) {
        $connection->issue(['site.read','site.diagnostics'],$admin->ID);
    }
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
            $bytes=str_replace($original,$candidate,$bytes);
            $needle="if (! defined('ABSPATH')) { exit; }";
            check(str_contains($bytes,$needle),'fixture bootstrap marker');
            if ($broken) {
                $bytes=str_replace($needle,$needle."\nthrow new RuntimeException('DISPOSABLE_PLUGIN_FATAL_FIXTURE');",$bytes);
            }
        }
        check($zip->addFromString('site-manager/'.$relative,$bytes),'candidate entry');
    }
    check($zip->close(),'candidate finalized');
    $sha=hash_file('sha256',$zipPath);
    $proof=(new PluginPackageArchiveGuard())->inspect($zipPath,$candidate,$sha);
    $engine=new PluginPackageInstallEngine($privateDirectory);
    $active=get_option('active_plugins');
    $content=wp_insert_post(['post_type'=>'post','post_status'=>'draft','post_title'=>'Self-update fixture','post_content'=>'Preserve me'],true);
    check(is_int($content) && $content>0,'content fixture');
    update_option('site_manager_disposable_self_update_baseline',['content'=>$content,'active'=>$active,'tree'=>$before['tree_sha256'],'original'=>$original,'candidate'=>$candidate,'candidate_tree'=>$proof['tree_sha256']],false);
    blocked(fn()=>$engine->apply($zipPath,$candidate,$sha,$before['tree_sha256'],$proof['tree_sha256'],false));
    blocked(fn()=>$engine->apply($zipPath,$candidate,$sha,str_repeat('0',64),$proof['tree_sha256'],true));
    check(! get_option($journalKey),'invalid attempts created no journal');
    $result=$engine->apply($zipPath,$candidate,$sha,$before['tree_sha256'],$proof['tree_sha256'],true);
    @unlink($zipPath);
    check($result['status']==='installed_pending_runtime' && $result['verified']===false && $result['tree_verified']===true,'installed but runtime NOT yet verified');
    check(get_option('active_plugins')===$active,'activation options preserved');
    echo $broken ? "DISPOSABLE_WP_PLUGIN_INSTALL_TREE=PASSED\n" : "DISPOSABLE_WP_PLUGIN_HEALTHY_INSTALL_TREE=PASSED\n";
} elseif ($stage==='restore') {
    check(! defined('SITE_MANAGER_VERSION'),'broken plugin did not load in independent recovery');
    remove_all_filters('pre_option_active_plugins');
    foreach (['PluginPackageArchiveGuard','PluginPackageRecoveryStore','PluginPackageInstallEngine'] as $class) {
        require '/tmp/sm-plugin-recovery-worker/'.$class.'.php';
    }
    $journal=get_option($journalKey);
    check(is_array($journal) && $journal['status']==='installed_pending_runtime','retained installation journal');
    $engine=new PluginPackageInstallEngine($privateDirectory);
    blocked(fn()=>$engine->restore(str_repeat('f',32),true));
    blocked(fn()=>$engine->restore($journal['id'],false));
    $result=$engine->restore($journal['id'],true);
    check($result['status']==='restored_pending_runtime' && $result['verified']===false && $result['tree_verified']===true,'independent restore verified tree');
    echo "DISPOSABLE_WP_PLUGIN_INDEPENDENT_RESTORE=PASSED\n";
} elseif (in_array($stage,['verify','verify-healthy'],true)) {
    $baseline=get_option('site_manager_disposable_self_update_baseline');
    $restored=$stage==='verify';
    check(defined('SITE_MANAGER_VERSION') && SITE_MANAGER_VERSION===($restored?$baseline['original']:$baseline['candidate']),'fresh expected runtime loaded');
    $journal=get_option($journalKey);
    check($journal['status']===($restored?'restored_pending_runtime':'installed_pending_runtime'),'expected journal');
    check((new PluginPackageRecoveryStore())->fingerprint($root)['tree_sha256']===($restored?$baseline['tree']:$baseline['candidate_tree']),'expected exact tree readback');
    check(get_option('active_plugins')===$baseline['active'] && get_post_status($baseline['content'])==='draft'
        && get_post_field('post_content',$baseline['content'])==='Preserve me','content and active plugins unchanged');
    $engine=new PluginPackageInstallEngine($privateDirectory);
    $receipt=$engine->runtimeReceipt($journal['id']);
    check($receipt['local_verified']===true && $receipt['remote_verified']===false && $receipt['rollback_available']===true,'local proof is not external MCP proof');
    blocked(fn()=>$engine->runtimeReceipt(str_repeat('f',32)));
    blocked(fn()=>$engine->finalize($journal['id'],false));
    $state=get_option(\SiteManager\Adapters\WordPress\RemoteConnectionStore::OPTION);
    $drift=$state;
    $drift['generation']++;
    update_option(\SiteManager\Adapters\WordPress\RemoteConnectionStore::OPTION,$drift,false);
    blocked(fn()=>$engine->runtimeReceipt($journal['id']));
    update_option(\SiteManager\Adapters\WordPress\RemoteConnectionStore::OPTION,$state,false);
    $final=$engine->finalize($journal['id'],true);
    check($final['status']==='finalized_local' && $final['archive_removed']===true && $final['rollback_available']===false && ! get_option($journalKey),'explicit finalization releases journal and archive');
    echo $restored ? "DISPOSABLE_WP_PLUGIN_FRESH_RUNTIME_RESTORED=PASSED\n" : "DISPOSABLE_WP_PLUGIN_FRESH_RUNTIME_UPDATED=PASSED\n";
    echo "DISPOSABLE_WP_PLUGIN_JOURNAL_FINALIZED=PASSED\n";
} else {
    throw new RuntimeException('Unknown fixture stage');
}
