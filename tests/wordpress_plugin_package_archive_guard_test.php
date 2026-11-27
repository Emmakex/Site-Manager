<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use SiteManager\Adapters\WordPress\PluginPackageArchiveGuard;

function check(bool $ok,string $label): void {
    if (! $ok) { throw new RuntimeException($label); }
}
if (! class_exists(ZipArchive::class)) {
    echo "PLUGIN_PACKAGE_ARCHIVE_GUARD=SKIPPED_NO_ZIP\n";
    exit(0);
}
$dir=sys_get_temp_dir().'/sm-plugin-guard-'.bin2hex(random_bytes(8));
mkdir($dir,0700);
$files=[
    'site-manager.php'=>"<?php\n/**\n * Plugin Name: Site Manager\n * Version: 0.12.4\n * Requires at least: 6.6\n * Requires PHP: 8.1\n */\n",
    'readme.txt'=>'Site Manager test fixture',
    'vendor/autoload.php'=>'<?php return true;',
    'vendor/composer/autoload_classmap.php'=>'<?php return [];',
    'src/Adapters/WordPress/Plugin.php'=>'<?php // fixture',
    'src/Adapters/WordPress/UpgradeGuard.php'=>'<?php // fixture',
    'src/Adapters/WordPress/RemoteAccess.php'=>'<?php // fixture',
    'src/Adapters/WordPress/RemoteWriteAccess.php'=>'<?php // fixture',
    'src/Core/Contracts/PlatformAdapter.php'=>'<?php // fixture',
];
$guard=new PluginPackageArchiveGuard();
$path=$dir.'/package.zip';
function makeZip(string $path,array $files,?string $symlink=null): string {
    $z=new ZipArchive();
    check($z->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)===true,'fixture zip opened');
    foreach ($files as $name=>$content) {
        check($z->addFromString('site-manager/'.$name,$content),'fixture file added');
    }
    if ($symlink!==null) {
        $z->setExternalAttributesName('site-manager/'.$symlink,ZipArchive::OPSYS_UNIX,0120777<<16);
    }
    check($z->close(),'fixture zip closed');
    return hash_file('sha256',$path);
}
function reject(callable $fn,string $label): void {
    try { $fn(); } catch (DomainException) { return; }
    throw new RuntimeException('Unexpected acceptance: '.$label);
}
try {
    $sha=makeZip($path,$files);
    $proof=$guard->inspect($path,'0.12.4',$sha);
    check($proof['version']==='0.12.4' && $proof['plugin']==='site-manager/site-manager.php'
        && $proof['files']===count($files) && $proof['requires_php']==='8.1'
        && $proof['requires_wordpress']==='6.6','exact official package identity');
    $hashes=array_map(static fn(string $s)=>hash('sha256',$s),$files);
    ksort($hashes,SORT_STRING);
    check($proof['tree_sha256']===hash('sha256',json_encode($hashes,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),'full tree fingerprint');
    reject(fn()=>$guard->inspect($path,'0.12.5',$sha),'wrong version');
    reject(fn()=>$guard->inspect($path,'0.12.4',str_repeat('0',64)),'wrong digest');
    foreach (['../outside.php','src/../../escape.php','src/.hidden.php','src\\escape.php','other.php','tests/debug.php','SRC/other.php','src/Adapters/WordPress/plugin.php','src/file/child.php'] as $name) {
        $bad=$files+[$name=>'<?php // unsafe'];
        if ($name==='src/file/child.php') { $bad['src/file']='collision'; }
        $digest=makeZip($path,$bad);
        reject(fn()=>$guard->inspect($path,'0.12.4',$digest),$name);
    }
    $bad=$files;
    unset($bad['vendor/autoload.php']);
    $digest=makeZip($path,$bad);
    reject(fn()=>$guard->inspect($path,'0.12.4',$digest),'missing runtime');
    $bad=$files+['src/link.php'=>'../outside.php'];
    $digest=makeZip($path,$bad,'src/link.php');
    reject(fn()=>$guard->inspect($path,'0.12.4',$digest),'symlink');
    $bad=$files+['src/large.php'=>str_repeat('x',8_000_001)];
    $digest=makeZip($path,$bad);
    reject(fn()=>$guard->inspect($path,'0.12.4',$digest),'oversized uncompressed file');
    $bad=$files;
    $bad['site-manager.php']=str_replace('Plugin Name: Site Manager','Plugin Name: Other Plugin',$bad['site-manager.php']);
    $digest=makeZip($path,$bad);
    reject(fn()=>$guard->inspect($path,'0.12.4',$digest),'wrong plugin identity');
    echo "PLUGIN_PACKAGE_ARCHIVE_GUARD=PASSED\n";
} finally {
    if (is_file($path)) { unlink($path); }
    rmdir($dir);
}
