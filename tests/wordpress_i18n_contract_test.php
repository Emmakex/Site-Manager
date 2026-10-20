<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/src/Adapters/WordPress/Plugin.php');
$po = file_get_contents($root . '/wordpress-plugin/site-manager/languages/site-manager-es_ES.po');
$adapter = file_get_contents($root . '/src/Adapters/WordPress/WordPressAdapter.php');

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(false !== $plugin, 'WordPress plugin source must be readable');
$assert(false !== $po, 'Spanish PO catalogue must be readable');
$assert(false !== $adapter, 'WordPress adapter source must be readable');

$assert(str_contains($plugin, "add_action('init', [self::class, 'loadTranslations'], 1)"), 'translation loader must run on init');
$assert(str_contains($plugin, "str_starts_with(strtolower($locale), 'es')"), 'all Spanish locales must use the bundled Spanish catalogue');
$assert(str_contains($plugin, "site-manager-es_ES.mo"), 'compiled Spanish catalogue must be loaded');
$assert(str_contains($plugin, "__('Platform', 'site-manager')"), 'dashboard labels must use the WordPress text domain');
$assert(str_contains($plugin, "__('Detected %1\$d · Understood %2\$d · Governable %3\$d · Authorized %4\$d · Blocked %5\$d', 'site-manager')"), 'capability summary must be translatable');
$assert(str_contains($plugin, 'translateBlocker'), 'governance blocker presentation must be localized at render time');

$assert(str_contains($po, 'msgstr "Panel de Site Manager"'), 'Spanish dashboard title translation is required');
$assert(str_contains($po, 'msgstr "Plataforma"'), 'Spanish platform label translation is required');
$assert(str_contains($po, 'msgstr "Mapa de capacidades"'), 'Spanish capability map translation is required');
$assert(str_contains($po, 'msgstr "Pendiente para gobernar"'), 'Spanish governance-gap heading translation is required');

$assert(str_contains($adapter, 'SM-2 safe WordPress Core write adapter is not implemented yet.'), 'stored capability blockers must remain canonical and language-neutral');
$assert(! str_contains($adapter, 'El adaptador seguro de escritura'), 'Spanish presentation text must not leak into the adapter contract');

echo "PASS: WordPress English/Spanish i18n contract\n";
