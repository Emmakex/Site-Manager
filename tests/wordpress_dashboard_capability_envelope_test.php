<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SiteManager\Adapters\WordPress\Plugin;
use SiteManager\Core\Model\Capability;
use SiteManager\Core\Model\CapabilityGraph;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$plugin = new Plugin();
$method = new ReflectionMethod(Plugin::class, 'capabilityById');
$method->setAccessible(true);

$content = new Capability(
    id: 'content.write',
    provider: 'wordpress',
    detected: true,
    understood: true,
    governable: true,
    operations: ['content.update'],
    requiredScopes: ['content.write'],
);
$seo = new Capability(
    id: 'seo.write',
    provider: 'wordpress',
    detected: true,
    understood: true,
    governable: true,
    operations: ['seo.update'],
    requiredScopes: ['seo.write'],
    metadata: [
        'provider_adapter' => 'seo-geo-theme',
        'write_mode' => 'semantic-native-sources',
        'writable_inputs' => [
            'title' => ['source' => 'post_title'],
            'description' => ['source' => 'post_excerpt'],
        ],
    ],
);

$graph = new CapabilityGraph([$content, $seo]);
$persisted = $graph->jsonSerialize();

$assert(is_array($persisted['capabilities'][0] ?? null), 'CapabilityGraph persistence must contain plain arrays, not Capability objects');
$assert(is_array($persisted['capabilities'][1] ?? null), 'all persisted capabilities must be normalized arrays');

$resolvedEnvelope = $method->invoke($plugin, $persisted, 'seo.write');
$assert(true === ($resolvedEnvelope['governable'] ?? false), 'dashboard must resolve seo.write from the real CapabilityGraph persisted envelope');
$assert('seo-geo-theme' === ($resolvedEnvelope['metadata']['provider_adapter'] ?? null), 'dashboard must preserve provider adapter metadata from the persisted capability graph');

$direct = [$content->jsonSerialize(), $seo->jsonSerialize()];
$resolvedDirect = $method->invoke($plugin, $direct, 'seo.write');
$assert('seo.write' === ($resolvedDirect['id'] ?? null), 'dashboard capability resolver must remain compatible with a direct normalized capability list');

$missing = $method->invoke($plugin, $persisted, 'navigation.missing');
$assert([] === $missing, 'dashboard capability resolver must return an empty capability for unknown ids');

echo "PASS: WordPress dashboard real persisted capability envelope regression\n";
