<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SiteManager\Adapters\WordPress\Plugin;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$plugin = new Plugin();
$method = new ReflectionMethod(Plugin::class, 'capabilityById');
$method->setAccessible(true);

$seo = [
    'id' => 'seo.write',
    'provider' => 'wordpress',
    'detected' => true,
    'understood' => true,
    'governable' => true,
    'operations' => ['seo.update'],
    'required_scopes' => ['seo.write'],
    'blockers' => [],
    'metadata' => [
        'provider_adapter' => 'seo-geo-theme',
        'write_mode' => 'semantic-native-sources',
        'writable_inputs' => [
            'title' => ['source' => 'post_title'],
            'description' => ['source' => 'post_excerpt'],
        ],
    ],
];
$content = [
    'id' => 'content.write',
    'provider' => 'wordpress',
    'detected' => true,
    'understood' => true,
    'governable' => true,
    'operations' => ['content.update'],
    'required_scopes' => ['content.write'],
    'blockers' => [],
    'metadata' => [],
];

$enveloped = ['capabilities' => [$content, $seo]];
$resolvedEnvelope = $method->invoke($plugin, $enveloped, 'seo.write');
$assert(true === ($resolvedEnvelope['governable'] ?? false), 'dashboard must resolve seo.write from CapabilityGraph::jsonSerialize envelope');
$assert('seo-geo-theme' === ($resolvedEnvelope['metadata']['provider_adapter'] ?? null), 'dashboard must preserve provider adapter metadata from persisted capability graph');

$resolvedDirect = $method->invoke($plugin, [$content, $seo], 'seo.write');
$assert('seo.write' === ($resolvedDirect['id'] ?? null), 'dashboard capability resolver must remain compatible with a direct capability list');

$missing = $method->invoke($plugin, $enveloped, 'navigation.missing');
$assert([] === $missing, 'dashboard capability resolver must return an empty capability for unknown ids');

echo "PASS: WordPress dashboard persisted capability envelope regression\n";
