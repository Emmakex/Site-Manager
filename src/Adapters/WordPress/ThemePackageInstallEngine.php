<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

use Closure;
use DomainException;

/**
 * Internal WordPress Theme package executor.
 *
 * This is NOT a public filesystem API or an alternative Gateway transport.
 * A future REST adapter must hydrate an already authorized, unexpired plan and
 * supply only a CI-proven, locally staged package. Callers must never accept
 * local paths or installer callbacks from client/Gateway request payloads.
 */
final class ThemePackageInstallEngine
{
    private const LOCK_OPTION = 'site_manager_theme_package_write_lock';

    /**
     * Installer seam is used solely by disposable WordPress acceptance tests.
     * In production the driver is WordPress's official Theme_Upgrader.
     *
     * @param null|Closure(string):bool $installer
     */
    public function __construct(private readonly ?Closure $installer = null) {}

    /**
     * Install the single approved Theme ZIP, retain an independently verified
     * backup for explicit rollback, auto-restore if apply/readback fails.
     *
     * @return array<string,mixed> Internal result: contains private backup
     *                            location and MUST be sanitized before MCP.
     */
    public function apply(
        string $trustedPackagePath,
        string $themeSlug,
        string $expectedPackageSha256,
        string $expectedBeforeTreeSha256,
        string $expectedAfterTreeSha256,
        bool $confirmed = false,
    ): array {
        if (! $confirmed) {
            throw new DomainException('theme_package_confirmation_required');
        }
        $this->assertIdentity($themeSlug, $expectedPackageSha256);
        $this->assertIdentity($themeSlug, $expectedBeforeTreeSha256);
        $this->assertIdentity($themeSlug, $expectedAfterTreeSha256);
        $themeDir = $this->eligibleThemeDirectory($themeSlug);
        $trees = new ThemePackageTreeFingerprint();
        $expected = $trees->archive($trustedPackagePath, $themeSlug, $expectedPackageSha256);
        if (! hash_equals($expectedAfterTreeSha256, (string) $expected['tree_sha256'])) {
            throw new DomainException('approved_package_tree_mismatch');
        }
        $before = $trees->installed($themeDir, $themeSlug);
        if (! hash_equals($expectedBeforeTreeSha256, (string) $before['tree_sha256'])) {
            throw new DomainException('stale_installed_theme');
        }
        if ($expectedBeforeTreeSha256 === $expectedAfterTreeSha256) {
            return ['status'=>'already_installed', 'verified'=>true, 'changed'=>false];
        }

        $token = $this->lock();
        $backupStore = new ThemePackageBackupStore();
        $backup = null;
        try {
            // Lock is held before backing up; double-readback still protects
            // against external WP/editor changes racing with this operation.
            $backup = $backupStore->capture($themeDir, $themeSlug, $expectedBeforeTreeSha256);
            $installed = false;
            $failure = 'theme_package_install_failed';
            try {
                $installed = $this->install($trustedPackagePath);
                if ($installed && $this->activeThemeUnchanged($themeSlug)) {
                    $after = $trees->installed($themeDir, $themeSlug);
                    if (hash_equals($expectedAfterTreeSha256, (string) $after['tree_sha256'])) {
                        return [
                            'status'=>'verified',
                            'verified'=>true,
                            'changed'=>true,
                            'theme_slug'=>$themeSlug,
                            'previous_tree_sha256'=>$expectedBeforeTreeSha256,
                            'installed_tree_sha256'=>$expectedAfterTreeSha256,
                            'backup'=>$backup, // PRIVATE: never expose in sanitized MCP/commands.
                        ];
                    }
                    $failure = 'theme_package_readback_mismatch';
                } elseif ($installed) {
                    $failure = 'active_theme_changed';
                }
            } catch (\Throwable) {
                $failure = 'theme_package_install_exception';
            }

            // Never claim verified after a failed updater response. Restore
            // before returning, and distinguish partial/manual recovery.
            $recovered = $this->restoreBackup($backupStore, $backup, $themeDir, $themeSlug);
            return [
                'status'=>$recovered ? 'rolled_back' : 'manual_recovery_required',
                'verified'=>false,
                'recovered'=>$recovered,
                'error'=>$failure,
                'backup'=>$backup, // PRIVATE: retained if manual intervention required.
                'previous_tree_sha256'=>$expectedBeforeTreeSha256,
            ];
        } finally {
            $this->unlock($token);
        }
    }

    /**
     * Explicit rollback of an approved successful apply. The current tree must
     * still equal the applied release; otherwise fail closed on target drift.
     *
     * @param array{path:string,zip_sha256:string,tree_sha256:string,files:int,bytes:int} $backup
     * @return array{status:string,verified:bool,restored_tree_sha256?:string}
     */
    public function rollback(
        array $backup,
        string $themeSlug,
        string $expectedCurrentTreeSha256,
        bool $confirmed = false,
    ): array {
        if (! $confirmed) {
            throw new DomainException('theme_package_confirmation_required');
        }
        $this->assertIdentity($themeSlug, $expectedCurrentTreeSha256);
        $themeDir = $this->eligibleThemeDirectory($themeSlug);
        $trees = new ThemePackageTreeFingerprint();
        $current = $trees->installed($themeDir, $themeSlug);
        if (! hash_equals($expectedCurrentTreeSha256, (string) $current['tree_sha256'])) {
            throw new DomainException('stale_installed_theme');
        }
        $token = $this->lock();
        try {
            $store = new ThemePackageBackupStore();
            $this->validateBackupMetadata($backup);
            $store->verifyRetained(
                $backup['path'], $themeSlug, $backup['zip_sha256'], $backup['tree_sha256']
            );
            if (! $this->install($backup['path']) || ! $this->activeThemeUnchanged($themeSlug)) {
                return ['status'=>'manual_recovery_required', 'verified'=>false];
            }
            $after = $trees->installed($themeDir, $themeSlug);
            if (! hash_equals($backup['tree_sha256'], (string) $after['tree_sha256'])) {
                return ['status'=>'manual_recovery_required', 'verified'=>false];
            }
            $store->deleteRetained($backup['path']);
            return [
                'status'=>'rolled_back',
                'verified'=>true,
                'restored_tree_sha256'=>$backup['tree_sha256'],
            ];
        } finally {
            $this->unlock($token);
        }
    }

    /** @param array<string,mixed> $backup */
    private function restoreBackup(
        ThemePackageBackupStore $store,
        array $backup,
        string $themeDir,
        string $slug,
    ): bool {
        try {
            $this->validateBackupMetadata($backup);
            $store->verifyRetained($backup['path'], $slug, $backup['zip_sha256'], $backup['tree_sha256']);
            if (! $this->install($backup['path']) || ! $this->activeThemeUnchanged($slug)) {
                return false;
            }
            $after = (new ThemePackageTreeFingerprint())->installed($themeDir, $slug);
            if (! hash_equals($backup['tree_sha256'], (string) $after['tree_sha256'])) {
                return false;
            }
            $store->deleteRetained($backup['path']);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function eligibleThemeDirectory(string $slug): string
    {
        if (! function_exists('get_stylesheet') || ! function_exists('get_template')
            || get_stylesheet() !== $slug || get_template() !== $slug
            || (function_exists('is_multisite') && is_multisite())) {
            throw new DomainException('theme_package_not_active_or_child');
        }
        if (! function_exists('wp_get_environment_type')
            || ! in_array(wp_get_environment_type(), ['local','development','staging'], true)) {
            throw new DomainException('theme_package_staging_only');
        }
        if (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS) {
            throw new DomainException('theme_package_filesystem_disabled');
        }
        if (! function_exists('wp_is_file_mod_allowed') || ! wp_is_file_mod_allowed('theme_package_deploy')) {
            throw new DomainException('theme_package_filesystem_disabled');
        }
        if (! function_exists('current_user_can') || ! current_user_can('manage_options')
            || ! current_user_can('install_themes') || ! current_user_can('update_themes')) {
            throw new DomainException('theme_package_permissions_denied');
        }
        if (! function_exists('get_filesystem_method')
            && defined('ABSPATH') && is_file(ABSPATH . 'wp-admin/includes/file.php')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (! function_exists('get_filesystem_method') || get_filesystem_method() !== 'direct') {
            throw new DomainException('theme_package_direct_filesystem_required');
        }
        if (! function_exists('get_stylesheet_directory')) {
            throw new DomainException('theme_package_theme_path_unavailable');
        }
        $root = realpath((string) get_stylesheet_directory());
        if ($root === false || ! is_dir($root) || is_link((string) get_stylesheet_directory())
            || basename($root) !== $slug) {
            throw new DomainException('theme_package_theme_path_unavailable');
        }
        return $root;
    }

    private function activeThemeUnchanged(string $slug): bool
    {
        return function_exists('get_stylesheet') && function_exists('get_template')
            && get_stylesheet() === $slug && get_template() === $slug;
    }

    private function assertIdentity(string $slug, string $digest): void
    {
        if (! preg_match('/^[a-z0-9][a-z0-9-]{1,79}$/D', $slug)
            || ! preg_match('/^[a-f0-9]{64}$/D', $digest)) {
            throw new DomainException('invalid_package_identity');
        }
    }

    /** @param array<string,mixed> $backup */
    private function validateBackupMetadata(array $backup): void
    {
        if (count($backup) !== 5 || ! isset($backup['path'], $backup['zip_sha256'], $backup['tree_sha256'],
            $backup['files'], $backup['bytes']) || ! is_string($backup['path'])
            || ! is_string($backup['zip_sha256']) || ! is_string($backup['tree_sha256'])
            || ! preg_match('/^[a-f0-9]{64}$/D', $backup['zip_sha256'])
            || ! preg_match('/^[a-f0-9]{64}$/D', $backup['tree_sha256'])
            || ! is_int($backup['files']) || $backup['files'] < 2
            || ! is_int($backup['bytes']) || $backup['bytes'] < 1) {
            throw new DomainException('invalid_retained_backup');
        }
    }

    private function lock(): string
    {
        if (! function_exists('add_option') || ! function_exists('get_option')
            || ! function_exists('delete_option')) {
            throw new DomainException('theme_package_lock_unavailable');
        }
        $token = bin2hex(random_bytes(16));
        // WordPress options table has a unique option_name constraint: add_option
        // cannot overwrite another currently active operation.
        if (! add_option(self::LOCK_OPTION, $token, '', false)) {
            throw new DomainException('theme_package_operation_in_progress');
        }
        return $token;
    }

    private function unlock(string $token): void
    {
        if (hash_equals($token, (string) get_option(self::LOCK_OPTION, ''))) {
            delete_option(self::LOCK_OPTION);
        }
    }

    private function install(string $trustedLocalZip): bool
    {
        if ($this->installer !== null) {
            return ($this->installer)($trustedLocalZip) === true;
        }
        if (! defined('ABSPATH')) {
            throw new DomainException('wordpress_upgrader_unavailable');
        }
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skins.php';
        if (! class_exists(\Theme_Upgrader::class) || ! class_exists(\Automatic_Upgrader_Skin::class)) {
            throw new DomainException('wordpress_upgrader_unavailable');
        }
        $upgrader = new \Theme_Upgrader(new \Automatic_Upgrader_Skin());
        // Official WordPress API, local verified ZIP only; never a URL/shell.
        return true === $upgrader->install($trustedLocalZip, ['overwrite_package'=>true]);
    }
}
