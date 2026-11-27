<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

use DomainException;
use ZipArchive;

/**
 * Read-only gate for the official Site Manager plugin package.
 * Fixed plugin identity; no generic plugin installer, extraction, URL, or write.
 * Provenance, staging, permission and update lifecycle are separate gates.
 */
final class PluginPackageArchiveGuard
{
    private const PREFIX = 'site-manager/';
    private const MAX_ARCHIVE = 10_000_000;
    private const MAX_TOTAL = 40_000_000;
    private const MAX_FILE = 8_000_000;
    private const MAX_ENTRIES = 2000;
    private const REQUIRED = [
        'site-manager.php','readme.txt','vendor/autoload.php',
        'vendor/composer/autoload_classmap.php',
        'src/Adapters/WordPress/Plugin.php','src/Adapters/WordPress/UpgradeGuard.php',
        'src/Adapters/WordPress/RemoteAccess.php','src/Adapters/WordPress/RemoteWriteAccess.php',
        'src/Core/Contracts/PlatformAdapter.php',
    ];

    /** @return array<string,int|string|bool> */
    public function inspect(string $trustedLocalZip,string $expectedVersion,string $expectedDigest): array
    {
        if (! preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D',$expectedVersion)
            || ! preg_match('/^[a-f0-9]{64}$/D',$expectedDigest)) {
            throw new DomainException('plugin_package_invalid_identity');
        }
        if (! class_exists(ZipArchive::class) || ! is_file($trustedLocalZip)
            || is_link($trustedLocalZip) || ! is_readable($trustedLocalZip)) {
            throw new DomainException('plugin_package_unavailable');
        }
        $size = filesize($trustedLocalZip);
        if (! is_int($size) || $size<100 || $size>self::MAX_ARCHIVE
            || ! hash_equals($expectedDigest,(string)hash_file('sha256',$trustedLocalZip))) {
            throw new DomainException('plugin_package_size_or_digest_mismatch');
        }
        $zip = new ZipArchive();
        if (true!==$zip->open($trustedLocalZip,ZipArchive::CHECKCONS)) {
            throw new DomainException('plugin_package_invalid_zip');
        }
        try {
            if ($zip->numFiles<count(self::REQUIRED) || $zip->numFiles>self::MAX_ENTRIES) {
                throw new DomainException('plugin_package_entry_limit');
            }
            $paths=[]; $hashes=[]; $total=0; $header=null;
            for ($i=0;$i<$zip->numFiles;++$i) {
                $stat=$zip->statIndex($i);
                $name=$stat['name'] ?? null;
                if (! is_string($name) || ! str_starts_with($name,self::PREFIX) || strlen($name)>512
                    || preg_match('/[\x00-\x20\x7f\\\\:]/',$name)) {
                    throw new DomainException('plugin_package_unsafe_path');
                }
                $dir=str_ends_with($name,'/');
                $relative=substr($name,strlen(self::PREFIX));
                $relative=rtrim($relative,'/');
                if ($relative==='' && ! $dir) { throw new DomainException('plugin_package_unsafe_path'); }
                if ($relative!=='') {
                    foreach (explode('/',$relative) as $segment) {
                        if ($segment==='' || $segment==='.' || $segment==='..' || str_starts_with($segment,'.')) {
                            throw new DomainException('plugin_package_unsafe_path');
                        }
                    }
                    $root=explode('/',$relative)[0];
                    if (! in_array($root,['site-manager.php','readme.txt','src','vendor','languages'],true)
                        || (in_array($root,['site-manager.php','readme.txt'],true) && ($dir || $relative!==$root))
                        || (in_array($root,['src','vendor','languages'],true) && $relative===$root && ! $dir)) {
                        throw new DomainException('plugin_package_unexpected_entry');
                    }
                }
                $folded=strtolower($relative);
                if (isset($paths[$folded])) { throw new DomainException('plugin_package_duplicate_entry'); }
                $paths[$folded]=$dir;
                if (! $zip->getExternalAttributesIndex($i,$os,$attributes)
                    || ($os===ZipArchive::OPSYS_UNIX && ! in_array(($attributes>>16)&0170000,[0,$dir?0040000:0100000],true))
                    || (int)($stat['encryption_method'] ?? 0)!==0) {
                    throw new DomainException('plugin_package_unsupported_entry');
                }
                $raw=(int)($stat['size'] ?? -1); $compressed=(int)($stat['comp_size'] ?? -1);
                if ($raw<0 || $compressed<0 || ($dir && $raw!==0) || $raw>self::MAX_FILE
                    || $total+$raw>self::MAX_TOTAL || ($raw>0 && $compressed===0)
                    || ($compressed>0 && $raw>400*$compressed)) {
                    throw new DomainException('plugin_package_expansion_limit');
                }
                if ($dir) { continue; }
                $bytes=$zip->getFromIndex($i);
                if (! is_string($bytes) || strlen($bytes)!==$raw) { throw new DomainException('plugin_package_unreadable_entry'); }
                $hashes[$relative]=hash('sha256',$bytes);
                $total+=$raw;
                if ($relative==='site-manager.php') { $header=substr($bytes,0,8192); }
            }
            foreach ($paths as $path=>$isDir) {
                $parent=dirname($path);
                while ($parent!=='.' && $parent!=='') {
                    if (isset($paths[$parent]) && $paths[$parent]===false) {
                        throw new DomainException('plugin_package_file_directory_collision');
                    }
                    $parent=dirname($parent);
                }
            }
            foreach (self::REQUIRED as $required) {
                if (! isset($hashes[$required])) { throw new DomainException('plugin_package_required_file_missing'); }
            }
            if (! is_string($header) || $this->header($header,'Plugin Name')!=='Site Manager'
                || $this->header($header,'Version')!==$expectedVersion
                || ! preg_match('/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/D',$this->header($header,'Requires PHP'))
                || ! preg_match('/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/D',$this->header($header,'Requires at least'))) {
                throw new DomainException('plugin_package_header_mismatch');
            }
            ksort($hashes,SORT_STRING);
            return ['plugin'=>'site-manager/site-manager.php','version'=>$expectedVersion,
                'requires_php'=>$this->header($header,'Requires PHP'),'requires_wordpress'=>$this->header($header,'Requires at least'),
                'package_sha256'=>$expectedDigest,'tree_sha256'=>hash('sha256',json_encode($hashes,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),
                'files'=>count($hashes),'bytes'=>$total,'archive_bytes'=>$size];
        } finally { $zip->close(); }
    }

    private function header(string $header,string $name): string
    {
        if (preg_match_all('/^[ \t*#\/]*'.preg_quote($name,'/').':[ \t]*([^\r\n]+)$/mi',$header,$matches)!==1) {
            return '';
        }
        return trim($matches[1][0]);
    }
}
