<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use SiteManager\Adapters\WordPress\PluginPackageArchiveGuard;
use SiteManager\Adapters\WordPress\PluginPackageRecoveryStore;

function check(bool $ok,string $label): void {
    if (! $ok) { throw new RuntimeException($label); }
}
function reject(callable $fn,string $label): void {
    try { $fn(); } catch (DomainException) { return; }
    throw new RuntimeException('Unexpected acceptance: '.$label);
}
if (! class_exists(ZipArchive::class)) {
    echo "PLUGIN_PACKAGE_RECOVERY=SKIPPED_NO_ZIP\n";
    exit(0);
}
$dir=sys_get_temp_dir().'/sm-plugin-recovery-test-'.bin2hex(random_bytes(8));
$root=$dir.'/site-manager';
mkdir($root,0700,true);
$files=[
    'site-manager.php'=>"<?php\n/**\n * Plugin Name: Site Manager\n * Version: 0.12.3\n * Requires at least: 6.6\n * Requires PHP: 8.1\n */\n",
    'readme.txt'=>'Site Manager test fixture',
    'vendor/autoload.php'=>'<?php return true;',
    'vendor/composer/autoload_classmap.php'=>'<?php return [];',
    'src/Adapters/WordPress/Plugin.php'=>'<?php // fixture',
    'src/Adapters/WordPress/UpgradeGuard.php'=>'<?php // fixture',
    'src/Adapters/WordPress/RemoteAccess.php'=>'<?php // fixture',
    'src/Adapters/WordPress/RemoteWriteAccess.php'=>'<?php // fixture',
    'src/Core/Contracts/PlatformAdapter.php'=>'<?php // fixture',
];
$backup=null;
try {
    foreach ($files as $name=>$content) {
        $path=$root.'/'.$name;
        if (! is_dir(dirname($path))) { mkdir(dirname($path),0700,true); }
        file_put_contents($path,$content);
    }
    $private=$dir.'/private';
    mkdir($private,0700);
    $store=new PluginPackageRecoveryStore($private);
    check($store->storageReady(),'explicit private directory accepted');
    chmod($private,0755);
    clearstatcache(true,$private);
    check(! $store->storageReady(),'non-private directory denied');
    chmod($private,0700);
    clearstatcache(true,$private);
    symlink($private,$dir.'/alias');
    check(! (new PluginPackageRecoveryStore($dir.'/alias'))->storageReady(),'symlink storage denied');
    unlink($dir.'/alias');
    check(! (new PluginPackageRecoveryStore($dir.'/absent'))->storageReady(),'missing storage denied');
    $before=$store->fingerprint($root);
    check($before['files']===count($files),'complete fixture tree');
    reject(fn()=>$store->capture($root,'0.12.3',str_repeat('0',64)),'stale tree');
    reject(fn()=>$store->capture($root,'0.12.4',$before['tree_sha256']),'wrong installed version');
    $backup=$store->capture($root,'0.12.3',$before['tree_sha256']);
    check($backup['tree_sha256']===$before['tree_sha256'] && $backup['bytes']===$before['bytes'],'exact snapshot');
    check((fileperms($backup['path'])&0077)===0 && dirname($backup['path'])===$private,'archive private and pinned');
    check($store->verify($backup)['version']==='0.12.3','retained archive readback');
    file_put_contents($root.'/readme.txt','new code tree');
    check($store->fingerprint($root)['tree_sha256']!==$before['tree_sha256'],'live drift detected');
    check($store->verify($backup)['tree_sha256']===$before['tree_sha256'],'retained archive independent of live tree');
    reject(fn()=>$store->verify(array_replace($backup,['tree_sha256'=>str_repeat('f',64)])),'wrong retained tree');
    reject(fn()=>$store->verify(array_replace($backup,['zip_sha256'=>str_repeat('f',64)])),'wrong retained digest');
    symlink($root.'/readme.txt',$root.'/src/link.php');
    reject(fn()=>$store->fingerprint($root),'symlink rejected');
    unlink($root.'/src/link.php');
    file_put_contents($root.'/src/.hidden.php','<?php');
    reject(fn()=>$store->fingerprint($root),'hidden entry rejected');
    unlink($root.'/src/.hidden.php');
    file_put_contents($root.'/src/Adapters/WordPress/plugin.php','<?php');
    reject(fn()=>$store->fingerprint($root),'case collision rejected');
    unlink($root.'/src/Adapters/WordPress/plugin.php');
    chmod($backup['path'],0644);
    clearstatcache(true,$backup['path']);
    reject(fn()=>$store->verify($backup),'public permissions rejected');
    chmod($backup['path'],0600);
    clearstatcache(true,$backup['path']);
    file_put_contents($backup['path'],'corrupt',FILE_APPEND);
    clearstatcache(true,$backup['path']);
    reject(fn()=>$store->verify($backup),'changed archive refused');
    echo "PLUGIN_PACKAGE_RECOVERY=PASSED\n";
} finally {
    if (is_array($backup) && is_file($backup['path'])) { unlink($backup['path']); }
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $e) { if ($e->isDir() && ! $e->isLink()) { rmdir($e->getPathname()); } else { unlink($e->getPathname()); } }
    rmdir($dir);
}
