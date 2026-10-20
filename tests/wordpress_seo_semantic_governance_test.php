<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use InvalidArgumentException;
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

final class FakeSeoContentRuntime implements ContentRuntime
{
    /** @param array<string,mixed> $post */
    public function __construct(public array $post, public bool $allowed = true) {}

    public function read(int $postId): ?array
    {
        return $postId === (int) ($this->post['ID'] ?? 0) ? $this->post : null;
    }

    public function canEdit(int $postId): bool { return $this->allowed; }

    public function sanitize(string $field, mixed $value): string
    {
        return trim(is_scalar($value) ? (string) $value : '');
    }

    public function update(int $postId, array $fields): array
    {
        if (! $this->allowed) {
            return ['success'=>false,'post_id'=>$postId,'error'=>'denied'];
        }
        foreach ($fields as $field => $value) {
            $this->post[$field] = $value;
        }
        $seconds = ((int) substr((string) ($this->post['post_modified_gmt'] ?? '00'), -2)) + 1;
        $this->post['post_modified_gmt'] = '2026-01-01 00:00:' . str_pad((string) min($seconds, 59), 2, '0', STR_PAD_LEFT);
        return ['success'=>true,'post_id'=>$postId,'error'=>''];
    }
}

$seoAuthority = [
    'state'=>'resolved-semantic-native-write-ready',
    'authority_resolved'=>true,
    'authority'=>[
        'id'=>'seo-geo-theme',
        'type'=>'theme-native',
        'label'=>'SEO/GEO Theme',
        'adapter'=>'seo-geo-theme-native',
        'outputs'=>['title-meta','canonical','robots','open-graph','schema','hreflang','sitemap'],
    ],
    'candidates'=>[],
    'conflict'=>false,
    'write_adapter_ready'=>true,
    'safe_to_write_seo_metadata'=>true,
    'write_mode'=>'semantic-native-sources',
    'writable_inputs'=>[
        'title'=>['source'=>'post_title','reversible'=>true],
        'description'=>['source'=>'post_excerpt','reversible'=>true],
    ],
    'derived_outputs'=>[
        'canonical'=>['sources'=>['permalink'],'direct_input'=>null],
        'schema'=>['sources'=>['post_title','post_excerpt','content'],'direct_input'=>null],
    ],
    'outputs'=>[],
];

$snapshot = new DiscoverySnapshot(
    schemaVersion: '1',
    generatedAt: '2026-01-01T00:00:00+00:00',
    fingerprint: 'seo-semantic-fixture',
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

$runtime = new FakeSeoContentRuntime([
    'ID'=>77,
    'post_type'=>'page',
    'post_title'=>'Original SEO title',
    'post_name'=>'original-seo-title',
    'post_content'=>'Body',
    'post_excerpt'=>'Original SEO description',
    'post_status'=>'publish',
    'post_modified_gmt'=>'2026-01-01 00:00:01',
]);

$service = new SeoGovernanceService($runtime);
$graph = (new WordPressAdapter())->capabilities($snapshot);
$seo = $graph->get('seo.write');
$assert(true === $seo?->detected, 'semantic native SEO must be detected');
$assert(true === $seo?->understood, 'semantic native SEO must be understood');
$assert(true === $seo?->governable, 'evidenced semantic native SEO must be governable');
$assert('seo-geo-theme' === $seo?->provider, 'SEO capability must retain provider ownership');
$assert('semantic-native-sources' === ($seo?->metadata['write_mode'] ?? null), 'SEO capability must expose semantic native write mode');
$assert('authorized' === $seo?->state(['seo.write']), 'seo.write must authorize only with its explicit scope');

$request = new OperationRequest(
    capabilityId: 'seo.write',
    operation: 'seo.update',
    payload: ['post_id'=>77,'fields'=>['title'=>'Updated SEO title','description'=>'Updated SEO description']],
    write: true,
    riskClass: 'editorial-review',
    confirmationClass: 'explicit',
    reason: 'SM-3A.4 semantic SEO acceptance fixture',
);

$plan = $service->plan($request, $snapshot, $graph);
$assert(true === $plan->reversible, 'semantic SEO plan must be reversible');
$assert('seo-geo-theme-native' === $plan->adapter, 'semantic SEO plan must use the provider adapter');
$assert('Original SEO title' === ($plan->snapshot['target']['post_title'] ?? null), 'plan must snapshot native title');
$assert('Original SEO description' === ($plan->snapshot['target']['post_excerpt'] ?? null), 'plan must snapshot native excerpt');
$assert('Updated SEO title' === ($plan->changes['native_fields']['post_title'] ?? null), 'SEO title must map to native post_title');
$assert('Updated SEO description' === ($plan->changes['native_fields']['post_excerpt'] ?? null), 'SEO description must map to native post_excerpt');
$assert('Original SEO title' === $runtime->post['post_title'], 'planning must remain read-only');

$notConfirmed = $service->apply($plan, false);
$assert(false === $notConfirmed->success && 'confirmation-required' === $notConfirmed->status, 'SEO apply must require explicit confirmation');

$applied = $service->apply($plan, true);
$assert(true === $applied->success && 'applied' === $applied->status, 'confirmed semantic SEO plan must apply');
$assert('Updated SEO title' === $runtime->post['post_title'], 'apply must update native title source');
$assert('Updated SEO description' === $runtime->post['post_excerpt'], 'apply must update native description source');

$verified = $service->verify($plan, $applied);
$assert(true === $verified->verified && 'verified' === $verified->status, 'semantic SEO apply must verify');

$rollbackNeedsConfirmation = $service->rollback($plan, $applied, false);
$assert(false === $rollbackNeedsConfirmation->success && 'confirmation-required' === $rollbackNeedsConfirmation->status, 'SEO rollback must require explicit confirmation');

$rolledBack = $service->rollback($plan, $applied, true);
$assert(true === $rolledBack->success && 'rolled-back' === $rolledBack->status, 'SEO rollback must restore native sources');
$assert('Original SEO title' === $runtime->post['post_title'], 'rollback must restore title');
$assert('Original SEO description' === $runtime->post['post_excerpt'], 'rollback must restore excerpt');

$stalePlan = $service->plan($request, $snapshot, $graph);
$runtime->post['post_title'] = 'Human edit after SEO plan';
$runtime->post['post_modified_gmt'] = '2026-01-01 00:00:59';
$staleResult = $service->apply($stalePlan, true);
$assert(false === $staleResult->success && 'stale-plan' === $staleResult->status, 'SEO apply must reject target drift after planning');
$assert('Human edit after SEO plan' === $runtime->post['post_title'], 'stale SEO plan must preserve later human edits');

$unsupportedCaught = false;
try {
    $unsupported = new OperationRequest(
        capabilityId: 'seo.write',
        operation: 'seo.update',
        payload: ['post_id'=>77,'fields'=>['canonical'=>'https://other.example/']],
        write: true,
        riskClass: 'editorial-review',
        confirmationClass: 'explicit',
    );
    $service->plan($unsupported, $snapshot, $graph);
} catch (InvalidArgumentException) {
    $unsupportedCaught = true;
}
$assert($unsupportedCaught, 'semantic SEO writer must reject unsupported direct canonical writes');

echo "PASS: WordPress SM-3A.4 semantic SEO governance contract\n";
