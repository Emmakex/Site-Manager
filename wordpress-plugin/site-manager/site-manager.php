<?php
/**
 * Plugin Name: Site Manager
 * Description: Discovers the real WordPress installation and exposes a safe capability/governance map.
 * Version: 0.5.1
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: Emmake
 * Text Domain: site-manager
 * Domain Path: /languages
 */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

if (! defined('SITE_MANAGER_VERSION')) {
    define('SITE_MANAGER_VERSION', '0.5.1');
}
if (! defined('SITE_MANAGER_PLUGIN_FILE')) {
    define('SITE_MANAGER_PLUGIN_FILE', __FILE__);
}

$autoloaders = [
    __DIR__ . '/vendor/autoload.php',
    dirname(__DIR__, 2) . '/vendor/autoload.php', // repository checkout only
];
$loaded = false;
foreach ($autoloaders as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        $loaded = true;
        break;
    }
}
if (! $loaded) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p><strong>Site Manager:</strong> runtime autoload is missing. Install an official Site Manager release ZIP.</p></div>';
    });
    return;
}

add_action('plugins_loaded', static function (): void {
    load_plugin_textdomain('site-manager', false, dirname(plugin_basename(__FILE__)) . '/languages');
    \SiteManager\Adapters\WordPress\UpgradeGuard::ensure();
}, 5);

register_activation_hook(__FILE__, [\SiteManager\Adapters\WordPress\Plugin::class, 'activate']);
add_action('plugins_loaded', [\SiteManager\Adapters\WordPress\Plugin::class, 'boot'], 10);
add_action('plugins_loaded', [\SiteManager\Adapters\WordPress\DiagnosticsPage::class, 'boot'], 11);
