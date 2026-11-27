<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SiteManager\Adapters\WordPress\DraftCreationRuntime;
use SiteManager\Adapters\WordPress\DraftCreationService;
use SiteManager\Adapters\WordPress\WordPressAdapter;
use SiteManager\Core\Model\DiscoverySnapshot;
use SiteManager\Core\Model\OperationRequest;
use SiteManager\Core\Model\PlatformIdentity;
use SiteManager\Core\Support\CanonicalJson;

function check(bool $ok, string $message): void
{
    if (! $ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}
function rejects(callable $fn, string $message): void
{
    try { $fn(); } catch (InvalidArgumentException|LogicException) { return; }
    check(false, $message);
}
final class FakeDraftRuntime implements DraftCreationRuntime
{
    public ?array $post = null;
    public bool $allowed = true;
    public bool $collision = false;
    public int $writes = 0;
    public bool $modifyOnInsert = false;
    public function canCreate(string $type): bool { return $this->allowed; }
    public function sanitize(string $field, mixed $value): string { return trim((string) $value); }
    public function slugAvailable(string $slug): bool { return ! $this->collision && null === $this->post; }
    public function create(string $type, array $fields, string $planId): array
    {
        if (! $this->allowed || ! $this->slugAvailable($fields['post_name'])) { return ['success'=>false,'post_id'=>0,'error'=>'denied']; }
        $this->writes++;
        $this->post = ['ID'=>42,'post_type'=>$type,'creation_plan'=>$planId,'post_modified_gmt'=>'2026-01-01 01:00:00'] + $fields;
        if ($this->modifyOnInsert) { $this->post['post_content'] = 'Changed by hook'; }
        return ['success'=>true,'post_id'=>42,'error'=>''];
    }
    public function read(int $id): ?array { return $id === 42 ? $this->post : null; }
    public function trash(int $id, string $planId, string $expectedFingerprint): bool
    {
        if (null === $this->post || ! $this->allowed || $id !== 42
            || $this->post['creation_plan'] !== $planId || 'draft' !== $this->post['post_status']
            || hash('sha256', CanonicalJson::encode($this->post)) !== $expectedFingerprint) { return false; }
        $this->writes++;
        $this->post['post_status'] = 'trash';
        return true;
    }
}
$snapshot = new DiscoverySnapshot('1','2026-01-01T00:00:00Z','site-fingerprint',new PlatformIdentity('wordpress','7.x','wordpress-core'),[
    'content_types'=>[['name'=>'post'],['name'=>'page']], 'providers'=>[],
]);
$runtime = new FakeDraftRuntime();
$adapter = new WordPressAdapter(draftCreation: new DraftCreationService($runtime));
$graph = $adapter->capabilities($snapshot);
$fields = ['title'=>'New article','slug'=>'new-article','content'=>'<p>Text</p>','excerpt'=>'Summary'];
$request = static fn(array $p): OperationRequest => new OperationRequest('content.write','content.create',$p,true,'editorial-review','explicit','draft test');
$payload = ['post_type'=>'post','fields'=>$fields];
$plan = $adapter->plan($request($payload),$snapshot,$graph);
check($plan->changes['fields']['post_status']==='draft' && $runtime->writes===0,'planning must be draft-only and non-mutating');
check(!$adapter->apply($plan,false)->success && $runtime->writes===0,'confirmation mandatory');
$runtime->collision = true;
check($adapter->apply($plan,true)->status==='stale-plan' && $runtime->writes===0,'slug conflict after plan must block');
$runtime->collision = false;
$runtime->allowed = false;
check($adapter->apply($plan,true)->status==='permission-denied','permission rechecked at apply');
$runtime->allowed = true;
$result = $adapter->apply($plan,true);
check($result->success && $result->data['post_id']===42 && $adapter->verify($plan,$result)->verified,'create and readback exact draft');
check(!$adapter->apply($plan,true)->success && $runtime->writes===1,'repeat cannot duplicate draft');
check(!$adapter->rollback($plan,$result,false)->success,'rollback confirmation mandatory');
$original = $runtime->post;
$runtime->post['post_content']='Human edit';
check(!$adapter->rollback($plan,$result,true)->success && $runtime->post['post_content']==='Human edit','rollback refuses changed draft');
$runtime->post=$original;
check($adapter->rollback($plan,$result,true)->success && $runtime->post['post_status']==='trash','rollback verified in trash');
$runtime->post=null;
$runtime->modifyOnInsert=true;
$result=$adapter->apply($plan,true);
check($result->success && !$adapter->verify($plan,$result)->verified,'hook-altered payload fails verification with ID retained');
$runtime->post=null;
rejects(fn()=> $adapter->plan($request(['post_type'=>'attachment','fields'=>$fields]),$snapshot,$graph),'unsupported type must reject');
rejects(fn()=> $adapter->plan($request(['post_type'=>'post','fields'=>$fields+['status'=>'publish']]),$snapshot,$graph),'publish at creation must reject');
rejects(fn()=> $adapter->plan($request(['post_type'=>'post','fields'=>$fields+['meta'=>'arbitrary']]),$snapshot,$graph),'arbitrary metadata must reject');
rejects(fn()=> $adapter->plan($request(['post_type'=>'page','fields'=>array_replace($fields,['slug'=>''])]),$snapshot,$graph),'empty slug must reject');
$runtime->allowed=false;
rejects(fn()=> $adapter->plan($request($payload),$snapshot,$graph),'plan permission required');
echo "PASS: WordPress governed draft creation contract\n";
