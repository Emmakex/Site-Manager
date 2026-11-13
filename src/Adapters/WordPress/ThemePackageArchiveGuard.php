<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

use DomainException;
use ZipArchive;

/**
 * Immutable-package safety gate for future governed theme updates.
 *
 * The caller supplies a LOCAL trusted CI artifact (never a customer URL/path).
 * This class does not copy, extract, install, execute or activate anything.
 * Provenance, permission, site binding and plan/rollback belong to other gates.
 */
final class ThemePackageArchiveGuard
{
    private const MAX_ARCHIVE_BYTES = 20_000_000;
    private const MAX_ENTRIES = 2500;
    private const MAX_FILE_BYTES = 12_000_000;
    private const MAX_EXTRACTED_BYTES = 80_000_000;
    private const MAX_RATIO = 400;

    /**
     * @return array<string,int|string|bool>
     */
    public function inspect(string $localArchive, string $expectedSlug, string $expectedSha256): array
    {
        if (! preg_match('/^[a-z0-9][a-z0-9-]{1,79}$/D', $expectedSlug)
            || ! preg_match('/^[a-f0-9]{64}$/D', $expectedSha256)) {
            throw new DomainException('invalid_package_identity');
        }
        if (! class_exists(ZipArchive::class)) {
            throw new DomainException('zip_extension_unavailable');
        }
        // A local filename is solely an internal transport argument, never an MCP
        // or Command Gateway input. Its directory is selected by trusted code.
        if (! is_file($localArchive) || is_link($localArchive) || ! is_readable($localArchive)) {
            throw new DomainException('package_not_readable');
        }
        $size = filesize($localArchive);
        if (! is_int($size) || $size < 100 || $size > self::MAX_ARCHIVE_BYTES) {
            throw new DomainException('package_size_limit');
        }
        $digest = hash_file('sha256', $localArchive);
        if (! is_string($digest) || ! hash_equals($expectedSha256, $digest)) {
            throw new DomainException('package_digest_mismatch');
        }

        $zip = new ZipArchive();
        if (true !== $zip->open($localArchive, ZipArchive::CHECKCONS)) {
            throw new DomainException('package_invalid_zip');
        }
        try {
            if ($zip->numFiles < 2 || $zip->numFiles > self::MAX_ENTRIES) {
                throw new DomainException('package_entry_limit');
            }
            $entries = [];
            $total = 0;
            $files = 0;
            $prefix = $expectedSlug . '/';
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $stat = $zip->statIndex($i);
                if (! is_array($stat) || ! is_string($stat['name'] ?? null)) {
                    throw new DomainException('package_invalid_entry');
                }
                $name = $stat['name'];
                if (! str_starts_with($name, $prefix) || ! $this->safeEntry($name)) {
                    throw new DomainException('package_unsafe_path');
                }
                // Case-insensitive duplicate names are unsafe on common hosting
                // filesystems, even though they are legal in the ZIP format.
                $folded = strtolower($name);
                if (isset($entries[$folded])) {
                    throw new DomainException('package_duplicate_entry');
                }
                $entries[$folded] = true;

                $dir = str_ends_with($name, '/');
                if (! $zip->getExternalAttributesIndex($i, $opsys, $attributes)) {
                    throw new DomainException('package_invalid_attributes');
                }
                if ($opsys === ZipArchive::OPSYS_UNIX) {
                    $kind = (($attributes >> 16) & 0170000);
                    if ($kind !== 0 && $kind !== ($dir ? 0040000 : 0100000)) {
                        throw new DomainException('package_unsupported_file_type');
                    }
                }
                if (isset($stat['encryption_method']) && (int) $stat['encryption_method'] !== ZipArchive::EM_NONE) {
                    throw new DomainException('package_encrypted_entry');
                }
                $uncompressed = (int) ($stat['size'] ?? -1);
                $compressed = (int) ($stat['comp_size'] ?? -1);
                if ($uncompressed < 0 || $compressed < 0) {
                    throw new DomainException('package_invalid_entry_size');
                }
                if ($dir) {
                    if ($uncompressed !== 0) {
                        throw new DomainException('package_invalid_directory');
                    }
                    continue;
                }
                ++$files;
                if ($uncompressed > self::MAX_FILE_BYTES || $total + $uncompressed > self::MAX_EXTRACTED_BYTES
                    || ($uncompressed > 0 && $compressed === 0)
                    || ($compressed > 0 && $uncompressed > self::MAX_RATIO * $compressed)) {
                    throw new DomainException('package_expansion_limit');
                }
                $total += $uncompressed;
            }
            $style = $this->boundedFile($zip, $prefix . 'style.css', 131_072);
            if (false === preg_match('/^\s*Theme Name:\s*\S.+$/mi', $style)
                || 1 !== preg_match('/^\s*Version:\s*([^\r\n]+)$/mi', $style, $version)) {
                throw new DomainException('package_invalid_theme_header');
            }
            $versionString = trim((string) $version[1]);
            if (! preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][a-zA-Z0-9.-]+)?$/D', $versionString)) {
                throw new DomainException('package_invalid_theme_version');
            }

            $manifest = json_decode($this->boundedFile($zip, $prefix . 'release-integrity.json', 200_000), true);
            if (! is_array($manifest) || (int) ($manifest['schema_version'] ?? 0) !== 1
                || ($manifest['mode'] ?? null) !== 'seo-geo-theme-release-integrity'
                || ($manifest['theme_slug'] ?? null) !== $expectedSlug
                || ($manifest['theme_version'] ?? null) !== $versionString
                || ! in_array($manifest['release_channel'] ?? null, ['prestable','stable'], true)
                || ($manifest['runtime_root'] ?? null) !== 'inc/seo-geo-core'
                || ! is_array($manifest['runtime_files'] ?? null)) {
                throw new DomainException('package_invalid_release_manifest');
            }
            $hashes = $manifest['runtime_files'];
            if (count($hashes) < 1 || count($hashes) > 1200
                || (int) ($manifest['runtime_file_count'] ?? -1) !== count($hashes)) {
                throw new DomainException('package_invalid_runtime_inventory');
            }
            foreach ($hashes as $name=>$hash) {
                if (! is_string($name) || ! str_starts_with($name, 'inc/seo-geo-core/')
                    || ! $this->safeEntry($prefix . $name)
                    || ! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/D', $hash)
                    || ! isset($entries[strtolower($prefix . $name)])) {
                    throw new DomainException('package_invalid_runtime_inventory');
                }
                $actual = hash('sha256', $this->boundedFile($zip, $prefix . $name, self::MAX_FILE_BYTES));
                if (! hash_equals($hash, $actual)) {
                    throw new DomainException('package_runtime_digest_mismatch');
                }
            }
            if (! isset($hashes['inc/seo-geo-core/src/Runtime.php'])) {
                throw new DomainException('package_runtime_entry_missing');
            }
            ksort($hashes, SORT_STRING);
            $canonical = json_encode($hashes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (! is_string($manifest['runtime_tree_sha256'] ?? null)
                || ! hash_equals($manifest['runtime_tree_sha256'], hash('sha256', $canonical))) {
                throw new DomainException('package_runtime_tree_mismatch');
            }
            return [
                'theme_slug'=>$expectedSlug,
                'theme_version'=>$versionString,
                'package_sha256'=>$digest,
                'archive_bytes'=>$size,
                'file_count'=>$files,
                'uncompressed_bytes'=>$total,
                'runtime_file_count'=>count($hashes),
                'runtime_tree_sha256'=>$manifest['runtime_tree_sha256'],
                'stylesheet_sha256'=>hash('sha256', $style),
                'manifest_verified'=>true,
            ];
        } finally {
            $zip->close();
        }
    }

    private function safeEntry(string $name): bool
    {
        if (strlen($name) > 512 || str_contains($name, '\\')
            || str_contains($name, "\0") || str_starts_with($name, '/')
            || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
            return false;
        }
        $parts = explode('/', rtrim($name, '/'));
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return false;
            }
        }
        return true;
    }

    private function boundedFile(ZipArchive $zip, string $name, int $limit): string
    {
        $stat = $zip->statName($name);
        if (! is_array($stat) || (int) ($stat['size'] ?? -1) < 1
            || (int) $stat['size'] > $limit) {
            throw new DomainException('package_required_file_unavailable');
        }
        $content = $zip->getFromName($name);
        if (! is_string($content) || strlen($content) !== (int) $stat['size']) {
            throw new DomainException('package_required_file_unreadable');
        }
        return $content;
    }
}
