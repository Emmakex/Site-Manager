<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SiteManager\Adapters\WordPress\ContentRuntime;
use SiteManager\Adapters\WordPress\SeoGovernanceService;
use SiteManager\Adapters\WordPress\WordPressAdapter;
use SiteManager\Core\Model\DiscoverySnapshot;
use SiteManager\Core\Model\OperationRequest;
use SiteManager\Core\Model\PlatformIdentity;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

final class RegistryRoutingRuntime implements ContentRuntime
{
    /** @param array<string,mixed> $post */
    public function __construct(public array $post) {}
    public function read(int $postId): ?array { return $postId === (int) $this->post['ID'] ? $this->post : null; }
    public function canEdit(int $postId): bool { return true; }
    public function sanitize(string $field, mixed $value): string { return trim(is_scalar($value) ? (string) $value : ''); }
    public function update(int $postId, array $fields): array
    {
        foreach ($fields as $field => $value) { $this->post[$field] = $value; }
        $this->post['post_modified_gmt'] = '2026-01-01 00:00:02';
        return ['success'=>true,'post_id'=>$postId,'error'=>''];
    }
}

$seoAuthority = [
    'authority_resolved'=>true,
    'conflict'=>false,
    'authority'=>['id'=>'seo-geo-theme','label'=>'SEO/GEO Theme'],
    'write_adapter_ready'=>true,
    'safe_to_write_seo_metadata'=>true,
    'write_mode'=>'semantic-native-sources',
    'writable_inputs'=>[
        'title'=>['source'=>'post_title','reversible'=>true],
        'description'=>['source'=>'post_excerpt','reversible'=>true],
    ],
    'derived_outputs'=>['canonical'=>['sources'=>['permalink']]],
    'outputs'=>[],
];
$snapshot = new DiscoverySnapshot(
    schemaVersion: '1',
    generatedAt: '2026-01-01T00:00:00+00:00',
    fingerprint: 'registry-routing-fixture',
    platform: new PlatformIdentity('wordpress', '7.x', 'wordpress-core'),
    inventory: [
        'content_types'=>[['name'=>'page','builtin'=>true]],
        'providers'=>[],
        'navigation'=>['providers'=>[]],
        'seo_authority'=>$seoAuthority,
        'urls'=>['https'=>true,'rest'=>'https://example.test/wp-json/'],
        'routing'=>['permalink_structure'=>'/%postname%/'],
        'indexability'=>['search_visibility'=>'index'],
    ],
);
$runtime = new RegistryRoutingRuntime([
    'ID'=>91,
    'post_type'=>'page',
    'post_title'=>'Before',
    'post_name'=>'before',
    'post_content'=>'Body',
    'post_excerpt'=>'Before description',
    'post_status'=>'publish',
    'post_modified_gmt'=>'2026-01-01 00:00:01',
]);
$adapter = new WordPressAdapter(seo: new SeoGovernanceService($runtime));
$graph = $adapter->capabilities($snapshot);
$seo = $graph->get('seo.write');
$assert(true === $seo?->governable, 'registry-resolved provider must make seo.write governable');
$assert('seo-geo-theme' === ($seo?->metadata['provider_adapter'] ?? null), 'capability must expose resolved provider adapter');
$assert('seo-geo-theme-native' === ($seo?->metadata['plan_adapter'] ?? null), 'capability must expose persisted plan adapter id');

$request = new OperationRequest(
    capabilityId: 'seo.write',
    operation: 'seo.update',
    payload: ['post_id'=>91,'fields'=>['title'=>'After','description'=>'After description']],
    write: true,
    riskClass: 'editorial-review',
    confirmationClass: 'explicit',
    reason: 'SM-3B registry routing fixture',
);
$plan = $adapter->plan($request, $snapshot, $graph);
$assert('seo-geo-theme-native' === $plan->adapter, 'planning must route through the resolved provider adapter');
$notConfirmed = $adapter->apply($plan, false);
$assert(false === $notConfirmed->success && 'confirmation-required' === $notConfirmed->status, 'registry-routed apply must preserve confirmation guard');
$applied = $adapter->apply($plan, true);
$assert(true === $applied->success, 'registry-routed provider plan must apply');
$assert('After' === $runtime->post['post_title'], 'provider route must mutate the provider-owned source');
$verified = $adapter->verify($plan, $applied);
$assert(true === $verified->verified, 'registry-routed provider apply must verify');
$rolledBack = $adapter->rollback($plan, $applied, true);
$assert(true === $rolledBack->success, 'registry-routed provider plan must roll back');
$assert('Before' === $runtime->post['post_title'], 'registry-routed rollback must restore the source');

$unknownSnapshot = new DiscoverySnapshot(
    schemaVersion: '1',
    generatedAt: '2026-01-01T00:00:00+00:00',
    fingerprint: 'yoast-pending-fixture',
    platform: new PlatformIdentity('wordpress', '7.x', 'wordpress-core'),
    inventory: [
        'content_types'=>[['name'=>'page','builtin'=>true]],
        'providers'=>[['id'=>'yoast-seo','label'=>'Yoast SEO']],
        'navigation'=>['providers'=>[]],
        'seo_authority'=>[
            'authority_resolved'=>true,
            'conflict'=>false,
            'authority'=>['id'=>'yoast-seo','label'=>'Yoast SEO'],
            'candidates'=>[['id'=>'yoast-seo']],
            'outputs'=>[],
        ],
        'urls'=>['https'=>true,'rest'=>'https://example.test/wp-json/'],
        'routing'=>['permalink_structure'=>'/%postname%/'],
        'indexability'=>['search_visibility'=>'index'],
    ],
);
$unknownGraph = $adapter->capabilities($unknownSnapshot);
$assert(false === $unknownGraph->get('seo.write')?->governable, 'provider ownership without a registered adapter must stay blocked');

echo "PASS: WordPress SM-3B SEO registry routing\n";
