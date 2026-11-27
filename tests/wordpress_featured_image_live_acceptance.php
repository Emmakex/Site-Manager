<?php
declare(strict_types=1);

require '/var/www/html/wp-load.php';

use SiteManager\Adapters\WordPress\RemoteConnectionStore;

function check(bool $ok, string $message): void
{
    if (! $ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}
check(wp_get_environment_type() === 'staging' && (string) get_option('blog_public') === '0', 'disposable nonindexable staging required');
$admin = get_user_by('login','testadmin');
check($admin instanceof WP_User && $admin->ID > 0, 'disposable admin exists');
wp_set_current_user($admin->ID);
add_theme_support('post-thumbnails');
add_post_type_support('post', 'thumbnail');
$token = (string) ((new RemoteConnectionStore())->issue(['site.read','content.write'], $admin->ID)['token'] ?? '');
check(strlen($token) === 64, 'scoped connection created');
function callFeatured(string $stage, array $params, string $token): WP_REST_Response
{
    $request = new WP_REST_Request('POST','/site-manager/v1/write/content/featured-image/'.$stage);
    $request->set_header('content-type','application/json');
    if ($token !== '') { $request->set_header('authorization','Bearer '.$token); }
    $request->set_body(wp_json_encode($params));
    $response = rest_do_request($request);
    check($response instanceof WP_REST_Response, 'structured REST response');
    return $response;
}
function status(WP_REST_Response $r, int $expected, string $msg): array
{
    $body = $r->get_data();
    if ($r->get_status() !== $expected) {
        fwrite(STDERR, 'REST_STATUS='.$r->get_status().' code='.($body['error']['code'] ?? $body['data']['status'] ?? 'unknown')."\n");
    }
    check($r->get_status() === $expected, $msg);
    return $body['data'] ?? [];
}
$postId = wp_insert_post(['post_type'=>'post','post_status'=>'draft','post_title'=>'Featured image fixture','post_content'=>'Original article'], true);
check(is_int($postId) && $postId > 0,'draft fixture created');
$otherId = wp_insert_post(['post_type'=>'post','post_status'=>'private','post_title'=>'Private image fixture'],true);
check(is_int($otherId) && $otherId > 0,'private parent fixture created');
function imageFixture(int $parent, string $name): int
{
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jf2sAAAAASUVORK5CYII=', true);
    $upload = wp_upload_bits($name.'.png',null,$png);
    check(empty($upload['error']),'disposable PNG uploaded');
    $id = wp_insert_attachment(['post_title'=>$name,'post_mime_type'=>'image/png','post_status'=>'inherit'], $upload['file'], $parent, true);
    check(is_int($id) && $id > 0,'native image inserted');
    wp_update_attachment_metadata($id, ['width'=>1,'height'=>1,'file'=>_wp_relative_upload_path($upload['file']),'sizes'=>[]]);
    return $id;
}
$a = imageFixture($postId,'sm-ci-image-a');
$b = imageFixture($postId,'sm-ci-image-b');
$private = imageFixture($otherId,'sm-ci-private-image');
check(callFeatured('plan',['post_id'=>$postId,'attachment_id'=>$a],'')->get_status() >= 400,'bearer mandatory');
status(callFeatured('plan',['post_id'=>$postId,'attachment_id'=>$private],$token),409,'foreign private media blocked');
status(callFeatured('plan',['post_id'=>$postId,'attachment_id'=>$otherId],$token),409,'nonattachment rejected');
$plan = status(callFeatured('plan',['post_id'=>$postId,'attachment_id'=>$a],$token),200,'first featured image plan');
$id = $plan['plan']['plan_id'];
check((int) get_post_thumbnail_id($postId)===0,'plan is nonmutating');
status(callFeatured('apply',['plan_id'=>$id,'confirmed'=>false],$token),409,'explicit approval required');
$apply = status(callFeatured('apply',['plan_id'=>$id,'confirmed'=>true],$token),200,'native featured image apply');
check(($apply['verification']['verified'] ?? false)===true && (int) get_post_thumbnail_id($postId)===$a,'actual thumbnail assigned and verified');
status(callFeatured('apply',['plan_id'=>$id,'confirmed'=>true],$token),409,'replay refused');
$replacement = status(callFeatured('plan',['post_id'=>$postId,'attachment_id'=>$b],$token),200,'replacement planned');
$replaceId = $replacement['plan']['plan_id'];
status(callFeatured('apply',['plan_id'=>$replaceId,'confirmed'=>true],$token),200,'replace thumbnail');
check((int) get_post_thumbnail_id($postId)===$b,'replacement verified');
status(callFeatured('rollback',['plan_id'=>$replaceId,'confirmed'=>true],$token),200,'restore previous thumbnail');
check((int) get_post_thumbnail_id($postId)===$a,'previous thumbnail restored');
$stale = status(callFeatured('plan',['post_id'=>$postId,'attachment_id'=>$b],$token),200,'stale test plan');
delete_post_thumbnail($postId);
status(callFeatured('apply',['plan_id'=>$stale['plan']['plan_id'],'confirmed'=>true],$token),409,'human change blocks stale plan');
check((int) get_post_thumbnail_id($postId)===0,'human change preserved');
check(get_post_status($postId)==='draft' && get_post_field('post_content',$postId)==='Original article','status and content untouched');
check(get_post($a) && get_post($b) && get_post($private),'no media deleted');
echo "DISPOSABLE_WP_FEATURED_IMAGE_ACCEPTANCE=PASSED\n";
