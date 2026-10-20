<?php
/**
 * Plugin Name: Site Manager
 * Description: Discovers the real WordPress installation and exposes a safe capability/governance map.
 * Version: 0.1.0-dev
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: Emmake
 * Text Domain: site-manager
 */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

$autoloaders = [
    __DIR__ . '/vendor/autoload.php',
    dirname(__DIR__, 2) . '/vendor/autoload.php',
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
        echo '<div class="notice notice-error"><p><strong>Site Manager:</strong> Composer autoload is missing. Use a built release package or install dependencies in the repository checkout.</p></div>';
    });
    return;
}

register_activation_hook(__FILE__, [\SiteManager\Adapters\WordPress\Plugin::class, 'activate']);
add_action('plugins_loaded', [\SiteManager\Adapters\WordPress\Plugin::class, 'boot']);
