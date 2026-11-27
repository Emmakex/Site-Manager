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
// Verify native public-only discovery independently of featured image writes.
wp_set_current_user($admin->ID);
$publicParent = wp_insert_post(['post_type'=>'post','post_status'=>'publish','post_title'=>'Public media fixture'],true);
$protectedParent = wp_insert_post(['post_type'=>'post','post_status'=>'publish','post_title'=>'Protected media fixture','post_password'=>'fixture-password'],true);
check(is_int($publicParent) && is_int($protectedParent),'public and protected parents created');
$publicImage = imageFixture($publicParent,'sm-ci-public-image');
$protectedImage = imageFixture($protectedParent,'sm-ci-protected-image');
$orphan = imageFixture(0,'sm-ci-orphan-image');
update_post_meta($publicImage,'_wp_attachment_image_alt','Public fixture alt');
wp_set_current_user(0);
function callImages(int $page, string $token): WP_REST_Response
{
    $request = new WP_REST_Request('GET','/site-manager/v1/media/images');
    $request->set_query_params(['page'=>$page]);
    if ($token !== '') { $request->set_header('authorization','Bearer '.$token); }
    return rest_do_request($request);
}
check(callImages(1,'')->get_status() >= 400,'public image inventory requires authentication');
status(callImages(0,$token),400,'zero page rejected');
status(callImages(1001,$token),400,'oversized page rejected');
// The isolated Docker fixture is installed with an HTTP loopback identity.
// Confirm that insecure identity fails closed, then set HTTPS metadata only.
// This test runs local REST dispatch, not a network request or TLS simulation.
$originalHome = (string) get_option('home');
$originalSite = (string) get_option('siteurl');
$originalUploadUrl = get_option('upload_url_path', '');
$originalUploadBase = wp_get_upload_dir()['baseurl'];
check($originalHome === 'http://localhost:8088' && $originalSite === 'http://localhost:8088','expected disposable loopback identity');
status(callImages(1,$token),503,'insecure site identity rejected');
update_option('home','https://localhost:8088');
update_option('siteurl','https://localhost:8088');
// WP_CONTENT_URL is a bootstrap constant, so changing siteurl alone does
// not change the default upload URL in this same PHP process.
check(is_string($originalUploadBase) && str_starts_with($originalUploadBase,'http://localhost:8088/'),'expected loopback upload origin');
update_option('upload_url_path', 'https://'.substr($originalUploadBase,strlen('http://')));
wp_upload_dir(null,false,true);
check(str_starts_with((string) wp_get_attachment_url($publicImage),'https://localhost:8088/'),'fixture attachment has HTTPS origin');
$inventory = status(callImages(1,$token),200,'read actual public images');
check(($inventory['sample_only'] ?? false)===true && ($inventory['private_values_included'] ?? true)===false,'truthful bounded sample');
$ids = array_column($inventory['items'],'attachment_id');
check(in_array($publicImage,$ids,true),'public attachment visible');
foreach ([$a,$b,$private,$protectedImage,$orphan] as $excluded) {
    check(!in_array($excluded,$ids,true),'nonpublic or unassigned attachment excluded');
}
$row = array_values(array_filter($inventory['items'],static fn($item)=>$item['attachment_id']===$publicImage))[0];
check($row['alt']==='Public fixture alt' && $row['parent_id']===$publicParent && $row['mime']==='image/png','public metadata verified');
// An attachment URL provided by a plugin must remain inside the enrolled origin.
$foreignUrl = static function ($url, $id) use ($publicImage) {
    return (int) $id === $publicImage ? 'https://foreign.example.invalid/image.png' : $url;
};
add_filter('wp_get_attachment_url',$foreignUrl,10,2);
$foreignInventory = status(callImages(1,$token),200,'foreign image URL omitted');
check(!in_array($publicImage,array_column($foreignInventory['items'],'attachment_id'),true),'foreign origin excluded');
remove_filter('wp_get_attachment_url',$foreignUrl,10);
update_option('home',$originalHome);
update_option('siteurl',$originalSite);
update_option('upload_url_path',$originalUploadUrl);
wp_upload_dir(null,false,true);
echo "DISPOSABLE_WP_PUBLIC_IMAGE_INVENTORY=PASSED\n";
// Shared ALT is a distinct, explicitly approved operation.
function callAlt(string $stage, array $params, string $token): WP_REST_Response
{
    $request = new WP_REST_Request('POST','/site-manager/v1/write/content/image-alt/'.$stage);
    $request->set_header('content-type','application/json');
    if ($token !== '') { $request->set_header('authorization','Bearer '.$token); }
    $request->set_body(wp_json_encode($params));
    return rest_do_request($request);
}
$altIntent = ['attachment_id'=>$a,'parent_id'=>$postId,'alt'=>'Mesa de trabajo con portátil'];
check(callAlt('plan',$altIntent,'')->get_status()>=400,'ALT requires bearer');
status(callAlt('plan',array_replace($altIntent,['parent_id'=>$otherId]),$token),409,'foreign parent blocked');
status(callAlt('plan',array_replace($altIntent,['alt'=>'<b>HTML</b>']),$token),400,'HTML ALT blocked');
status(callAlt('plan',$altIntent+['meta_key'=>'arbitrary'],$token),400,'arbitrary metadata rejected');
check(!metadata_exists('post',$a,'_wp_attachment_image_alt'),'ALT initially absent');
$altPlan = status(callAlt('plan',$altIntent,$token),200,'ALT plan accepted');
$altId = $altPlan['plan']['plan_id'];
check(!metadata_exists('post',$a,'_wp_attachment_image_alt'),'ALT planning is nonmutating');
status(callAlt('apply',['plan_id'=>$altId,'confirmed'=>false],$token),409,'ALT confirmation required');
$altApply = status(callAlt('apply',['plan_id'=>$altId,'confirmed'=>true],$token),200,'ALT apply verified');
check(($altApply['verification']['verified'] ?? false)===true && get_post_meta($a,'_wp_attachment_image_alt',true)===$altIntent['alt'],'exact ALT readback');
status(callAlt('rollback',['plan_id'=>$altId,'confirmed'=>true],$token),200,'ALT rollback verified');
check(!metadata_exists('post',$a,'_wp_attachment_image_alt'),'missing ALT restored exactly');
update_post_meta($a,'_wp_attachment_image_alt','Original');
$altPlan2 = status(callAlt('plan',$altIntent,$token),200,'existing ALT replacement planned');
$altId2 = $altPlan2['plan']['plan_id'];
status(callAlt('apply',['plan_id'=>$altId2,'confirmed'=>true],$token),200,'existing ALT replacement');
status(callAlt('rollback',['plan_id'=>$altId2,'confirmed'=>true],$token),200,'existing ALT restored');
check(get_post_meta($a,'_wp_attachment_image_alt',true)==='Original','existing ALT exact restoration');
$altPlan3 = status(callAlt('plan',array_replace($altIntent,['alt'=>'']),$token),200,'empty decorative ALT planned');
update_post_meta($a,'_wp_attachment_image_alt','Human edit');
status(callAlt('apply',['plan_id'=>$altPlan3['plan']['plan_id'],'confirmed'=>true],$token),409,'stale ALT rejected');
check(get_post_meta($a,'_wp_attachment_image_alt',true)==='Human edit','human ALT preserved');
check(get_post_status($postId)==='draft' && get_post_field('post_content',$postId)==='Original article','ALT leaves post unchanged');
echo "DISPOSABLE_WP_IMAGE_ALT_ACCEPTANCE=PASSED\n";
echo "DISPOSABLE_WP_FEATURED_IMAGE_ACCEPTANCE=PASSED\n";
