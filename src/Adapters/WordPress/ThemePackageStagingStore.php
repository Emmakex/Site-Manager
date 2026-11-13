<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

use DomainException;

/**
 * Stages one CI-authorized Theme ZIP in a private WordPress-owned temporary
 * folder until a short-lived, explicit Theme package plan is applied.
 *
 * The remote Gateway must verify immutable GitHub provenance BEFORE supplying
 * these bytes; no caller-supplied URL, filesystem path, PHP or shell is accepted.
 */
final class ThemePackageStagingStore
{
    private const MAX_BYTES = 20_000_000;

    /** @return array<string,int|string|bool> Internal: path MUST NOT reach MCP/GitHub. */
    public function stage(string $zipBytes, string $slug, string $expectedSha256): array
    {
        if (! preg_match('/^[a-z0-9][a-z0-9-]{1,79}$/D', $slug)
            || ! preg_match('/^[a-f0-9]{64}$/D', $expectedSha256)) {
            throw new DomainException('invalid_package_identity');
        }
        if (strlen($zipBytes) < 100 || strlen($zipBytes) > self::MAX_BYTES) {
            throw new DomainException('package_size_limit');
        }
        if (! hash_equals($expectedSha256, hash('sha256', $zipBytes))) {
            throw new DomainException('package_digest_mismatch');
        }

        $dir = $this->tempRoot() . '/sm-release-' . bin2hex(random_bytes(16));
        if (! mkdir($dir, 0700) || ! is_dir($dir)) {
            throw new DomainException('release_staging_unavailable');
        }
        $path = $dir . '/theme.zip';
        try {
            if (file_put_contents($path, $zipBytes, LOCK_EX) !== strlen($zipBytes)) {
                throw new DomainException('release_staging_write_failed');
            }
            @chmod($path, 0600);
            $inspection = (new ThemePackageArchiveGuard())->inspect($path, $slug, $expectedSha256);
            $tree = (new ThemePackageTreeFingerprint())->archive($path, $slug, $expectedSha256);
            return [
                'path'=>$path, // WP-only, stored in private plan snapshot.
                'package_sha256'=>$expectedSha256,
                'theme_slug'=>$slug,
                'theme_version'=>(string) $inspection['theme_version'],
                'tree_sha256'=>(string) $tree['tree_sha256'],
                'file_count'=>(int) $tree['files'],
                'bytes'=>(int) $tree['bytes'],
                'manifest_verified'=>true,
            ];
        } catch (\Throwable $error) {
            @unlink($path);
            @rmdir($dir);
            throw $error;
        }
    }

    /** Assert retained plan bytes are private, unmodified and match approved release. */
    public function verify(string $privatePath, string $slug, string $packageSha256): array
    {
        $this->privatePath($privatePath);
        $archive = (new ThemePackageArchiveGuard())->inspect($privatePath, $slug, $packageSha256);
        $tree = (new ThemePackageTreeFingerprint())->archive($privatePath, $slug, $packageSha256);
        return [
            'package_sha256'=>$packageSha256,
            'theme_slug'=>$slug,
            'theme_version'=>$archive['theme_version'],
            'tree_sha256'=>$tree['tree_sha256'],
            'file_count'=>$tree['files'],
        ];
    }

    /** Delete only a securely created, WP-managed internal staging path. */
    public function release(string $privatePath): void
    {
        $this->privatePath($privatePath);
        if (! @unlink($privatePath) || ! @rmdir(dirname($privatePath))) {
            throw new DomainException('release_staging_cleanup_failed');
        }
    }

    private function privatePath(string $path): void
    {
        $real = realpath($path);
        $folder = dirname($real ?: $path);
        if ($real === false || is_link($path) || ! is_file($real)
            || basename($real) !== 'theme.zip'
            || dirname($folder) !== $this->tempRoot()
            || preg_match('/^sm-release-[a-f0-9]{32}$/D', basename($folder)) !== 1
            || is_link($folder) || ! is_readable($real)) {
            throw new DomainException('release_stage_not_private');
        }
    }

    private function tempRoot(): string
    {
        $root = realpath(function_exists('get_temp_dir') ? get_temp_dir() : sys_get_temp_dir());
        if ($root === false || ! is_dir($root) || ! is_writable($root)) {
            throw new DomainException('release_staging_unavailable');
        }
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
                throw new DomainException('release_staging_private_temp_required');
            }
        }
        return $root;
    }
}
