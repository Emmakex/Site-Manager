<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

use DomainException;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * Fixed-identity, bounded code-only recovery snapshot of Site Manager.
 * Internal primitive: not registered as REST, not a generic site backup.
 * Caller must derive installed directory from WP_PLUGIN_DIR.'/site-manager'.
 * Retained archives contain executable PHP; keep only outside web roots.
 */
final class PluginPackageRecoveryStore
{
    // The host worker may pin a durable private directory rather than relying
    // on WordPress's shared temporary directory (which may be public).
    public function __construct(private readonly ?string $privateDirectory = null) {}

    public function storageReady(): bool
    {
        try {
            $this->privateRoot();
            return true;
        } catch (DomainException) {
            return false;
        }
    }

    /** @return array{tree_sha256:string,files:int,bytes:int} */
    public function fingerprint(string $installedDirectory): array
    {
        [$root,$files,$bytes] = $this->scan($installedDirectory);
        $hashes=[];
        foreach ($files as $name=>$file) {
            $hash=hash_file('sha256',$file);
            if (! is_string($hash)) { throw new DomainException('plugin_recovery_unreadable_file'); }
            $hashes[$name]=$hash;
        }
        ksort($hashes,SORT_STRING);
        return ['tree_sha256'=>hash('sha256',json_encode($hashes,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),
            'files'=>count($hashes),'bytes'=>$bytes];
    }

    /** @return array<string,mixed> Internal only: path must never enter remote receipts. */
    public function capture(string $installedDirectory,string $installedVersion,string $expectedTree): array
    {
        if (! preg_match('/^[a-f0-9]{64}$/D',$expectedTree)
            || ! preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D',$installedVersion)
            || ! class_exists(ZipArchive::class)) {
            throw new DomainException('plugin_recovery_invalid_identity');
        }
        $before=$this->fingerprint($installedDirectory);
        if (! hash_equals($expectedTree,$before['tree_sha256'])) {
            throw new DomainException('plugin_recovery_stale_source');
        }
        [, $files]=$this->scan($installedDirectory);
        $path=tempnam($this->privateRoot(),'sm-plugin-');
        if (! is_string($path)) { throw new DomainException('plugin_recovery_storage_unavailable'); }
        try {
            if (dirname($path)!==$this->privateRoot() || ! chmod($path,0600)) {
                throw new DomainException('plugin_recovery_storage_unavailable');
            }
            $zip=new ZipArchive();
            if ($zip->open($path,ZipArchive::OVERWRITE)!==true) {
                throw new DomainException('plugin_recovery_archive_unavailable');
            }
            try {
                foreach ($files as $name=>$file) {
                    if (! $zip->addFile($file,'site-manager/'.$name)) {
                        throw new DomainException('plugin_recovery_archive_write_failed');
                    }
                }
            } finally {
                if (! $zip->close()) { throw new DomainException('plugin_recovery_archive_write_failed'); }
            }
            $sha=hash_file('sha256',$path);
            if (! is_string($sha)) { throw new DomainException('plugin_recovery_archive_unreadable'); }
            $proof=(new PluginPackageArchiveGuard())->inspect($path,$installedVersion,$sha);
            $after=$this->fingerprint($installedDirectory);
            if ($before!==$after || $proof['tree_sha256']!==$expectedTree
                || $proof['files']!==$before['files'] || $proof['bytes']!==$before['bytes']) {
                throw new DomainException('plugin_recovery_snapshot_mismatch');
            }
            return ['path'=>$path,'version'=>$installedVersion,'zip_sha256'=>$sha,
                'tree_sha256'=>$expectedTree,'files'=>$proof['files'],'bytes'=>$proof['bytes']];
        } catch (\Throwable $error) {
            @unlink($path);
            throw $error;
        }
    }

    /** Verify retained code package without trusting the currently installed version. */
    public function verify(array $backup): array
    {
        if (count($backup)!==6 || ! is_string($backup['path'] ?? null)
            || ! is_string($backup['version'] ?? null) || ! is_string($backup['zip_sha256'] ?? null)
            || ! is_string($backup['tree_sha256'] ?? null)
            || ! is_int($backup['files'] ?? null) || ! is_int($backup['bytes'] ?? null)) {
            throw new DomainException('plugin_recovery_invalid_receipt');
        }
        $real=realpath($backup['path']);
        if ($real===false || dirname($real)!==$this->privateRoot()
            || ! str_starts_with(basename($real),'sm-plugin-') || is_link($backup['path'])
            || ! is_file($real) || (fileperms($real)&0077)!==0) {
            throw new DomainException('plugin_recovery_not_private');
        }
        $proof=(new PluginPackageArchiveGuard())->inspect($real,$backup['version'],$backup['zip_sha256']);
        foreach (['tree_sha256','files','bytes'] as $key) {
            if ($proof[$key]!==$backup[$key]) { throw new DomainException('plugin_recovery_snapshot_mismatch'); }
        }
        return $proof;
    }

    /** @return array{string,array<string,string>,int} */
    private function scan(string $directory): array
    {
        $root=realpath($directory);
        if ($root===false || ! is_dir($root) || is_link($directory) || basename($root)!=='site-manager') {
            throw new DomainException('plugin_recovery_invalid_root');
        }
        $files=[]; $seen=[]; $bytes=0; $entries=0;
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            $name=str_replace(DIRECTORY_SEPARATOR,'/',substr($entry->getPathname(),strlen($root)+1));
            if (++$entries>2000 || $entry->isLink() || strlen($name)>499
                || preg_match('/[\x00-\x20\x7f\\\\:]/',$name)
                || preg_match('~(?:^|/)\.[^/]*(?:/|$)~',$name)
                || isset($seen[strtolower($name)])) {
                throw new DomainException('plugin_recovery_unsafe_entry');
            }
            $seen[strtolower($name)]=true;
            $first=explode('/',$name)[0];
            if (! in_array($first,['site-manager.php','readme.txt','src','vendor','languages'],true)
                || (in_array($first,['site-manager.php','readme.txt'],true) && ($entry->isDir() || $name!==$first))) {
                throw new DomainException('plugin_recovery_unexpected_entry');
            }
            if ($entry->isDir()) { continue; }
            $size=$entry->getSize();
            if (! $entry->isFile() || ! $entry->isReadable() || $size<0 || $size>8000000
                || $bytes+$size>40000000 || $entry->getPathname()!==realpath($entry->getPathname())) {
                throw new DomainException('plugin_recovery_unsafe_file');
            }
            $files[$name]=$entry->getPathname(); $bytes+=$size;
        }
        if (! isset($files['site-manager.php'],$files['vendor/autoload.php'],$files['readme.txt'])) {
            throw new DomainException('plugin_recovery_incomplete_tree');
        }
        return [$root,$files,$bytes];
    }

    private function privateRoot(): string
    {
        $candidate=$this->privateDirectory ?? (function_exists('get_temp_dir') ? get_temp_dir() : sys_get_temp_dir());
        $root=realpath($candidate);
        if ($root===false || ! is_dir($root) || ! is_writable($root)) {
            throw new DomainException('plugin_recovery_storage_unavailable');
        }
        if ($this->privateDirectory!==null && ($root!==$candidate || is_link($candidate)
            || (fileperms($root)&0077)!==0)) {
            throw new DomainException('plugin_recovery_requires_private_storage');
        }
        foreach ([defined('ABSPATH') ? ABSPATH : null,defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : null,
            $_SERVER['DOCUMENT_ROOT'] ?? null] as $webRoot) {
            if (! is_string($webRoot) || $webRoot==='') { continue; }
            $real=realpath($webRoot);
            if ($real!==false && ($root===$real || str_starts_with($root,rtrim($real,'/').'/'))) {
                throw new DomainException('plugin_recovery_requires_private_storage');
            }
        }
        return $root;
    }
}
