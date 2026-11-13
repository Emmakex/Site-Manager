<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

use DomainException;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * File-level installed-Theme readback, independent of version strings.
 *
 * WordPress remains authoritative: success means the FULL installed tree
 * equals the SHA-256-validated official release ZIP, not merely HTTP 200,
 * a green GitHub check or matching "Version: 0.1.1" headers.
 *
 * Never accepts a user-supplied path via REST/MCP; callers must derive the
 * local installation path from the active WordPress stylesheet identity.
 */
final class ThemePackageTreeFingerprint
{
    private const MAX_FILES = 2500;
    private const MAX_BYTES = 80_000_000;
    private const MAX_FILE_BYTES = 12_000_000;

    /** @return array<string,int|string> */
    public function installed(string $installedThemeDir, string $expectedSlug): array
    {
        $this->slug($expectedSlug);
        $root = realpath($installedThemeDir);
        if ($root === false || ! is_dir($root) || is_link($installedThemeDir)
            || basename($root) !== $expectedSlug) {
            throw new DomainException('invalid_installed_theme_path');
        }
        $hashes = [];
        $bytes = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink() || ! $entry->isFile() || ! $entry->isReadable()) {
                throw new DomainException('unsafe_installed_theme_entry');
            }
            $name = str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($root) + 1));
            if ($name === '' || strlen($name) > 512 || str_contains($name, '..')
                || preg_match('/[\x00-\x1f\x7f]/', $name)) {
                throw new DomainException('unsafe_installed_theme_entry');
            }
            $size = $entry->getSize();
            if (! is_int($size) || $size < 0 || $size > self::MAX_FILE_BYTES
                || $bytes + $size > self::MAX_BYTES || count($hashes) >= self::MAX_FILES) {
                throw new DomainException('installed_theme_size_limit');
            }
            $bytes += $size;
            $digest = hash_file('sha256', $entry->getPathname());
            if (! is_string($digest)) {
                throw new DomainException('unreadable_installed_theme_file');
            }
            $hashes[$name] = $digest;
        }
        return $this->summary($hashes, $bytes);
    }

    /** @return array<string,int|string> */
    public function archive(
        string $localOfficialZip,
        string $expectedSlug,
        string $expectedSha256
    ): array {
        $this->slug($expectedSlug);
        (new ThemePackageArchiveGuard())->inspect($localOfficialZip, $expectedSlug, $expectedSha256);
        $zip = new ZipArchive();
        if (true !== $zip->open($localOfficialZip, ZipArchive::CHECKCONS)) {
            throw new DomainException('package_invalid_zip');
        }
        try {
            $hashes = [];
            $bytes = 0;
            $prefix = $expectedSlug . '/';
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $stat = $zip->statIndex($i);
                if (! is_array($stat) || ! is_string($stat['name'] ?? null)) {
                    throw new DomainException('package_invalid_entry');
                }
                if (str_ends_with($stat['name'], '/')) {
                    continue;
                }
                $name = substr($stat['name'], strlen($prefix));
                $size = (int) ($stat['size'] ?? -1);
                if ($name === '' || $size < 0 || $size > self::MAX_FILE_BYTES
                    || $bytes + $size > self::MAX_BYTES) {
                    throw new DomainException('package_expansion_limit');
                }
                $content = $zip->getFromIndex($i);
                if (! is_string($content) || strlen($content) !== $size) {
                    throw new DomainException('package_unreadable_entry');
                }
                $bytes += $size;
                $hashes[$name] = hash('sha256', $content);
            }
            return $this->summary($hashes, $bytes);
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array{verified:bool,archive_sha256:string,installed_sha256:string,expected_files:int,installed_files:int}
     */
    public function compare(
        string $localOfficialZip,
        string $installedThemeDir,
        string $expectedSlug,
        string $expectedSha256,
    ): array {
        $expected = $this->archive($localOfficialZip, $expectedSlug, $expectedSha256);
        $installed = $this->installed($installedThemeDir, $expectedSlug);
        $match = $expected['files'] === $installed['files']
            && $expected['bytes'] === $installed['bytes']
            && hash_equals((string) $expected['tree_sha256'], (string) $installed['tree_sha256']);
        return [
            'verified'=>$match,
            'archive_sha256'=>(string) $expected['tree_sha256'],
            'installed_sha256'=>(string) $installed['tree_sha256'],
            'expected_files'=>(int) $expected['files'],
            'installed_files'=>(int) $installed['files'],
        ];
    }

    private function slug(string $slug): void
    {
        if (! preg_match('/^[a-z0-9][a-z0-9-]{1,79}$/D', $slug)) {
            throw new DomainException('invalid_package_identity');
        }
    }

    /** @param array<string,string> $files @return array{tree_sha256:string,files:int,bytes:int} */
    private function summary(array $files, int $bytes): array
    {
        if ($files === []) {
            throw new DomainException('empty_theme_tree');
        }
        ksort($files, SORT_STRING);
        $canonical = json_encode($files, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return [
            'tree_sha256'=>hash('sha256', $canonical),
            'files'=>count($files),
            'bytes'=>$bytes,
        ];
    }
}
