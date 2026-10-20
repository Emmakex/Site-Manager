<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SiteManager\Adapters\WordPress\ContentGovernanceService;
use SiteManager\Adapters\WordPress\ContentRuntime;
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

final class FakeContentRuntime implements ContentRuntime
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
        $value = is_scalar($value) ? (string) $value : '';
        return 'post_name' === $field ? strtolower(trim(str_replace(' ', '-', $value))) : trim($value);
    }

    public function update(int $postId, array $fields): array
    {
        if (! $this->allowed) {
            return ['success'=>false,'post_id'=>$postId,'error'=>'denied'];
        }
        foreach ($fields as $field => $value) {
            $this->post[$field] = $value;
        }
        $this->post['post_modified_gmt'] = '2026-01-01 00:00:' . str_pad((string) (((int) substr((string) $this->post['post_modified_gmt'], -2)) + 1), 2, '0', STR_PAD_LEFT);
        return ['success'=>true,'post_id'=>$postId,'error'=>''];
    }
}

$snapshot = new DiscoverySnapshot(
    schemaVersion: '1',
    generatedAt: '2026-01-01T00:00:00+00:00',
    fingerprint: 'site-structure-fingerprint',
    platform: new PlatformIdentity('wordpress', '7.x', 'wordpress-core'),
    inventory: [
        'content_types'=>[
            ['name'=>'post','builtin'=>true,'supports'=>['title','editor']],
            ['name'=>'page','builtin'=>true,'supports'=>['title','editor','page-attributes']],
        ],
        'providers'=>[],
        'urls'=>['https'=>true,'rest'=>'https://example.test/wp-json/'],
        'routing'=>['permalink_structure'=>'/%postname%/'],
        'indexability'=>['search_visibility'=>'index'],
    ],
);

$runtime = new FakeContentRuntime([
    'ID'=>42,
    'post_type'=>'page',
    'post_title'=>'Original title',
    'post_name'=>'original-title',
    'post_content'=>'Original content',
    'post_excerpt'=>'',
    'post_status'=>'draft',
    'post_modified_gmt'=>'2026-01-01 00:00:01',
]);
$service = new ContentGovernanceService($runtime);
$graph = (new WordPressAdapter())->capabilities($snapshot);
$assert(true === $graph->get('content.write')?->governable, 'content.write must be governable when native post/page content is detected');
$assert('governable' === $graph->get('content.write')?->state([]), 'content.write must remain unauthorized without the content.write scope');
$assert('authorized' === $graph->get('content.write')?->state(['content.write']), 'content.write must authorize with its explicit scope');

$request = new OperationRequest(
    capabilityId: 'content.write',
    operation: 'content.update',
    payload: ['post_id'=>42,'fields'=>['title'=>'Updated title','slug'=>'Updated Title','status'=>'publish']],
    write: true,
    riskClass: 'editorial-review',
    confirmationClass: 'explicit',
    reason: 'SM-2A acceptance fixture',
);

$plan = $service->plan($request, $snapshot, $graph);
$assert(true === $plan->reversible, 'content plan must be reversible');
$assert('Original title' === ($plan->snapshot['target']['post_title'] ?? null), 'plan must capture pre-change title');
$assert('Updated title' === ($plan->changes['fields']['post_title'] ?? null), 'plan must contain normalized desired title');
$assert('updated-title' === ($plan->changes['fields']['post_name'] ?? null), 'plan must normalize the desired slug');
$assert('Original title' === $runtime->post['post_title'], 'planning must not mutate WordPress content');

$notConfirmed = $service->apply($plan, false);
$assert(false === $notConfirmed->success && 'confirmation-required' === $notConfirmed->status, 'apply must reject missing explicit confirmation');
$assert('Original title' === $runtime->post['post_title'], 'rejected apply must not mutate content');

$applied = $service->apply($plan, true);
$assert(true === $applied->success && 'applied' === $applied->status, 'confirmed content plan must apply');
$assert('Updated title' === $runtime->post['post_title'], 'apply must update title');
$assert('publish' === $runtime->post['post_status'], 'apply must update publication state');

$verified = $service->verify($plan, $applied);
$assert(true === $verified->verified && 'verified' === $verified->status, 'applied content must verify');

$rollbackNeedsConfirmation = $service->rollback($plan, $applied, false);
$assert(false === $rollbackNeedsConfirmation->success && 'confirmation-required' === $rollbackNeedsConfirmation->status, 'rollback must require explicit confirmation');

$rolledBack = $service->rollback($plan, $applied, true);
$assert(true === $rolledBack->success && 'rolled-back' === $rolledBack->status, 'confirmed rollback must restore pre-change snapshot');
$assert('Original title' === $runtime->post['post_title'], 'rollback must restore title');
$assert('draft' === $runtime->post['post_status'], 'rollback must restore publication state');

$stalePlan = $service->plan($request, $snapshot, $graph);
$runtime->post['post_title'] = 'Human edit after plan';
$runtime->post['post_modified_gmt'] = '2026-01-01 00:00:59';
$staleResult = $service->apply($stalePlan, true);
$assert(false === $staleResult->success && 'stale-plan' === $staleResult->status, 'apply must reject target drift after planning');
$assert('Human edit after plan' === $runtime->post['post_title'], 'stale-plan rejection must preserve the human edit');

echo "PASS: WordPress SM-2A content governance contract\n";
