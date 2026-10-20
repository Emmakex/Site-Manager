<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

final class UpgradeGuard
{
    private const VERSION_OPTION = 'site_manager_runtime_version';
    private const DIRTY_OPTION = 'site_manager_discovery_dirty';

    public static function ensure(): void
    {
        $version = defined('SITE_MANAGER_VERSION') ? (string) SITE_MANAGER_VERSION : '';
        if ('' === $version) {
            return;
        }

        $stored = (string) get_option(self::VERSION_OPTION, '');
        if ($stored === $version) {
            return;
        }

        update_option(self::VERSION_OPTION, $version, false);
        update_option(self::DIRTY_OPTION, '1', false);
    }
}
