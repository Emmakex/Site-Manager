<?php
declare(strict_types=1);

/** Native REST acceptance on disposable CI WordPress only. */
require '/var/www/html/wp-load.php';

use SiteManager\Adapters\WordPress\RemoteConnectionStore;

function check(bool $ok, string $message): void
{
    if (! $ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}
check(wp_get_environment_type() === 'staging' && (string) get_option('blog_public') === '0', 'disposable nonindexable staging required');
check(class_exists(RemoteConnectionStore::class), 'official Site Manager package active');
$admin = get_user_by('login','testadmin');
check($admin instanceof WP_User && $admin->ID > 0, 'disposable administrator exists');
wp_set_current_user($admin->ID);
$token = (string) ((new RemoteConnectionStore())->issue(['site.read','content.write'], $admin->ID)['token'] ?? '');
check(strlen($token) === 64, 'scoped connection created');
function callCreate(string $stage, array $params, string $token): WP_REST_Response
{
    $request = new WP_REST_Request('POST','/site-manager/v1/write/content/create/'.$stage);
    $request->set_header('content-type','application/json');
    if ($token !== '') { $request->set_header('authorization','Bearer '.$token); }
    $request->set_body(wp_json_encode($params));
    $response = rest_do_request($request);
    check($response instanceof WP_REST_Response, 'structured REST response');
    return $response;
}
function requireStatus(WP_REST_Response $response, int $status, string $message): array
{
    if ($response->get_status() !== $status) {
        $body = $response->get_data();
        fwrite(STDERR, 'REST_STATUS='.$response->get_status().' code='.($body['error']['code'] ?? $body['data']['status'] ?? 'unknown')."\n");
    }
    check($response->get_status() === $status, $message);
    return $response->get_data()['data'] ?? [];
}
$baseline = [];
foreach (['post','page'] as $type) {
    $baseline[$type] = (int) wp_count_posts($type)->publish;
    $slug = 'sm-ci-native-draft-'.$type;
    $fields = ['title'=>'Disposable draft '.$type,'slug'=>$slug,'content'=>'<p>Texto de prueba: comillas " y barra \\.</p>','excerpt'=>'Resumen temporal'];
    $payload = ['post_type'=>$type,'fields'=>$fields];
    check(null === get_page_by_path($slug, OBJECT, ['post','page']), 'slug must start unused');
    check(callCreate('plan',$payload,'')->get_status() >= 400, 'bearer mandatory');
    check(callCreate('plan',['post_type'=>$type,'fields'=>$fields+['status'=>'publish']],$token)->get_status() >= 400, 'direct publication rejected');
    $planned = requireStatus(callCreate('plan',$payload,$token),200,'native draft plan');
    $planId = $planned['plan']['plan_id'] ?? '';
    check(is_string($planId) && preg_match('/^sm-plan-[a-f0-9]{24}$/D',$planId)===1,'opaque plan identity');
    check(($planned['changes']['after']['post_status'] ?? '')==='draft','draft-only preview');
    check(($planned['changes']['after']['post_content'] ?? '')===$fields['content'],'exact preview including backslash');
    check(null === get_page_by_path($slug, OBJECT, ['post','page']),'plan never creates draft');
    requireStatus(callCreate('apply',['plan_id'=>$planId,'confirmed'=>false],$token),409,'confirmation mandatory');
    $applied = requireStatus(callCreate('apply',['plan_id'=>$planId,'confirmed'=>true],$token),200,'native apply and verify');
    $id = (int) ($applied['execution']['data']['post_id'] ?? 0);
    $post = get_post($id);
    check($id>0 && $post instanceof WP_Post && $post->post_type===$type && $post->post_status==='draft','native draft identity');
    check($post->post_title===$fields['title'] && $post->post_name===$slug
        && $post->post_content===$fields['content'] && $post->post_excerpt===$fields['excerpt'],'exact WordPress content readback');
    check(($applied['verification']['verified'] ?? false)===true,'WordPress verification passed');
    requireStatus(callCreate('apply',['plan_id'=>$planId,'confirmed'=>true],$token),409,'apply replay refused');
    requireStatus(callCreate('plan',$payload,$token),409,'duplicate slug rejected');
    requireStatus(callCreate('rollback',['plan_id'=>$planId,'confirmed'=>false],$token),409,'rollback confirmation mandatory');
    $rolled = requireStatus(callCreate('rollback',['plan_id'=>$planId,'confirmed'=>true],$token),200,'native recoverable rollback');
    check(($rolled['rollback']['evidence']['trashed'] ?? false)===true && get_post_status($id)==='trash','native trash readback');
    check((int) wp_count_posts($type)->publish===$baseline[$type],'published content untouched');
    requireStatus(callCreate('rollback',['plan_id'=>$planId,'confirmed'=>true],$token),409,'rollback replay refused');
    echo "NATIVE_DRAFT_".strtoupper($type)."_ACCEPTANCE=PASSED\n";
}
check((string) get_option('blog_public')==='0','disposable site remains nonindexable');
echo "DISPOSABLE_WP_DRAFT_CREATION_ACCEPTANCE=PASSED\n";
