<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

use DomainException;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * Private, bounded pre-install backup of the active WordPress Theme.
 *
 * The source MUST be derived from get_stylesheet_directory() by the caller.
 * A backup is an internal recovery artifact, not an upload/remote ZIP and
 * does not require the official release manifest of the incoming package.
 * The backup path must NEVER appear in MCP / GitHub results.
 */
final class ThemePackageBackupStore
{
    private const MAX_FILES = 2500;
    private const MAX_BYTES = 80_000_000;
    private const MAX_ZIP_BYTES = 20_000_000;

    /** @return array{path:string,zip_sha256:string,tree_sha256:string,files:int,bytes:int} */
    public function capture(string $installedThemeDir, string $activeSlug, string $expectedTreeSha): array
    {
        $this->identity($activeSlug, $expectedTreeSha);
        $root = realpath($installedThemeDir);
        if ($root === false || ! is_dir($root) || is_link($installedThemeDir) || basename($root) !== $activeSlug) {
            throw new DomainException('invalid_installed_theme_path');
        }
        $snapshot = (new ThemePackageTreeFingerprint())->installed($root, $activeSlug);
        if (! hash_equals($expectedTreeSha, (string) $snapshot['tree_sha256'])) {
            throw new DomainException('stale_installed_theme');
        }

        $path = tempnam($this->privateTempDirectory(), 'sm-theme-');
        if (! is_string($path)) {
            throw new DomainException('private_backup_unavailable');
        }
        @chmod($path, 0600);
        $zip = new ZipArchive();
        if (true !== $zip->open($path, ZipArchive::OVERWRITE)) {
            @unlink($path);
            throw new DomainException('private_backup_unavailable');
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            $files = 0;
            foreach ($iterator as $entry) {
                if ($entry->isLink()) {
                    throw new DomainException('unsafe_installed_theme_entry');
                }
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($root) + 1));
                if (! $this->safeRelativeName($relative)) {
                    throw new DomainException('unsafe_installed_theme_entry');
                }
                $zipName = $activeSlug . '/' . $relative;
                if ($entry->isDir()) {
                    if (! $zip->addEmptyDir($zipName)) {
                        throw new DomainException('backup_archive_write_failed');
                    }
                } elseif ($entry->isFile() && $entry->isReadable()) {
                    ++$files;
                    if ($files > self::MAX_FILES || ! $zip->addFile($entry->getPathname(), $zipName)) {
                        throw new DomainException('backup_archive_write_failed');
                    }
                } else {
                    throw new DomainException('unsafe_installed_theme_entry');
                }
            }
        } catch (\Throwable $error) {
            $zip->close();
            @unlink($path);
            throw $error;
        }
        if (! $zip->close() || ! is_file($path) || filesize($path) > self::MAX_ZIP_BYTES) {
            @unlink($path);
            throw new DomainException('backup_archive_size_limit');
        }
        try {
            // Validate both the stored bytes and unchanged live source after ZIP
            // creation; otherwise a concurrent edit could produce a stale backup.
            $archive = $this->inspect($path, $activeSlug);
            $after = (new ThemePackageTreeFingerprint())->installed($root, $activeSlug);
            if ($archive['tree_sha256'] !== $expectedTreeSha || $after['tree_sha256'] !== $expectedTreeSha
                || $archive['files'] !== $snapshot['files'] || $archive['bytes'] !== $snapshot['bytes']) {
                throw new DomainException('backup_snapshot_mismatch');
            }
            $zipDigest = hash_file('sha256', $path);
            if (! is_string($zipDigest)) {
                throw new DomainException('backup_archive_unreadable');
            }
            return [
                'path'=>$path,
                'zip_sha256'=>$zipDigest,
                'tree_sha256'=>$expectedTreeSha,
                'files'=>(int) $archive['files'],
                'bytes'=>(int) $archive['bytes'],
            ];
        } catch (\Throwable $error) {
            @unlink($path);
            throw $error;
        }
    }

    /**
     * Verify a retained backup prior to invoking the WordPress updater for
     * restoration. Neither paths nor ZIP contents should reach a user-facing API.
     *
     * @return array{tree_sha256:string,files:int,bytes:int}
     */
    public function verifyRetained(string $backupPath, string $slug, string $expectedZipSha, string $expectedTreeSha): array
    {
        $this->identity($slug, $expectedTreeSha);
        if (! preg_match('/^[a-f0-9]{64}$/D', $expectedZipSha)) {
            throw new DomainException('invalid_backup_digest');
        }
        $root = $this->privateTempDirectory();
        $real = realpath($backupPath);
        if ($real === false || dirname($real) !== $root || ! str_starts_with(basename($real), 'sm-theme-')
            || is_link($backupPath) || ! is_file($real) || ! is_readable($real)) {
            throw new DomainException('backup_not_private');
        }
        $hash = hash_file('sha256', $real);
        if (! is_string($hash) || ! hash_equals($expectedZipSha, $hash)) {
            throw new DomainException('backup_archive_digest_mismatch');
        }
        $state = $this->inspect($real, $slug);
        if (! hash_equals($expectedTreeSha, $state['tree_sha256'])) {
            throw new DomainException('backup_tree_digest_mismatch');
        }
        return $state;
    }

    /**
     * Remove a completed backup by its trusted internal path only.
     */
    public function deleteRetained(string $backupPath): void
    {
        $real = realpath($backupPath);
        if ($real !== false && dirname($real) === $this->privateTempDirectory()
            && str_starts_with(basename($real), 'sm-theme-') && ! is_link($backupPath)) {
            @unlink($real);
        }
    }

    /** @return array{tree_sha256:string,files:int,bytes:int} */
    private function inspect(string $path, string $slug): array
    {
        $zip = new ZipArchive();
        if (true !== $zip->open($path, ZipArchive::CHECKCONS)) {
            throw new DomainException('backup_archive_unreadable');
        }
        try {
            if ($zip->numFiles < 2 || $zip->numFiles > self::MAX_FILES * 3) {
                throw new DomainException('backup_archive_entry_limit');
            }
            $hashes = [];
            $count = 0;
            $total = 0;
            $prefix = $slug . '/';
            $folded = [];
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $stat = $zip->statIndex($i);
                $name = is_array($stat) ? ($stat['name'] ?? null) : null;
                if (! is_string($name) || ! str_starts_with($name, $prefix)) {
                    throw new DomainException('backup_archive_unsafe_path');
                }
                $relative = substr($name, strlen($prefix));
                $directory = str_ends_with($relative, '/');
                $relative = rtrim($relative, '/');
                if (! $this->safeRelativeName($relative)) {
                    throw new DomainException('backup_archive_unsafe_path');
                }
                $lc = strtolower($name);
                if (isset($folded[$lc])) {
                    throw new DomainException('backup_archive_duplicate_entry');
                }
                $folded[$lc] = true;
                if (! $zip->getExternalAttributesIndex($i, $opsys, $attributes)) {
                    throw new DomainException('backup_archive_unsafe_type');
                }
                if ($opsys === ZipArchive::OPSYS_UNIX) {
                    $kind = (($attributes >> 16) & 0170000);
                    if ($kind !== 0 && $kind !== ($directory ? 0040000 : 0100000)) {
                        throw new DomainException('backup_archive_unsafe_type');
                    }
                }
                $size = (int) ($stat['size'] ?? -1);
                if ($directory) {
                    if ($size !== 0) {
                        throw new DomainException('backup_archive_unsafe_type');
                    }
                    continue;
                }
                if ($size < 0 || $size > 12_000_000 || $total + $size > self::MAX_BYTES || ++$count > self::MAX_FILES) {
                    throw new DomainException('backup_archive_size_limit');
                }
                $bytes = $zip->getFromIndex($i);
                if (! is_string($bytes) || strlen($bytes) !== $size) {
                    throw new DomainException('backup_archive_unreadable');
                }
                $hashes[$relative] = hash('sha256', $bytes);
                $total += $size;
            }
            if ($count < 2 || ! isset($hashes['style.css'], $hashes['functions.php'])) {
                throw new DomainException('backup_archive_incomplete');
            }
            ksort($hashes, SORT_STRING);
            $canonical = json_encode($hashes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            return ['tree_sha256'=>hash('sha256', $canonical), 'files'=>$count, 'bytes'=>$total];
        } finally {
            $zip->close();
        }
    }

    private function identity(string $slug, string $digest): void
    {
        if (! preg_match('/^[a-z0-9][a-z0-9-]{1,79}$/D', $slug)
            || ! preg_match('/^[a-f0-9]{64}$/D', $digest)) {
            throw new DomainException('invalid_package_identity');
        }
    }

    private function safeRelativeName(string $name): bool
    {
        if ($name === '' || strlen($name) > 512 || str_contains($name, '\\')
            || str_contains($name, "\0") || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            return false;
        }
        foreach (explode('/', $name) as $segment) {
            if ($segment === '' || $segment === '..' || $segment === '.') {
                return false;
            }
        }
        return true;
    }

    private function privateTempDirectory(): string
    {
        $root = realpath(function_exists('get_temp_dir') ? get_temp_dir() : sys_get_temp_dir());
        if ($root === false || ! is_dir($root) || ! is_writable($root)) {
            throw new DomainException('private_backup_unavailable');
        }
        // WordPress may fall back to wp-content when the system temp is not
        // writable. Never store a theme PHP backup in a publicly served tree.
        foreach ([
            defined('ABSPATH') ? ABSPATH : null,
            defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : null,
            $_SERVER['DOCUMENT_ROOT'] ?? null,
        ] as $webRoot) {
            if (! is_string($webRoot) || $webRoot === '') {
                continue;
            }
            $webRoot = realpath($webRoot);
            if ($webRoot !== false && ($root === $webRoot || str_starts_with($root, rtrim($webRoot, '/') . '/'))) {
                throw new DomainException('private_backup_location_required');
            }
        }
        return $root;
    }
}
