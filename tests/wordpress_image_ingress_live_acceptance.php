<?php
declare(strict_types=1);

require '/var/www/html/wp-load.php';

use SiteManager\Adapters\WordPress\NativeImageIngressRuntime;

function check(bool $ok,string $message): void
{
    if (! $ok) { fwrite(STDERR,"FAIL: $message\n"); exit(1); }
}
function blocked(callable $fn,string $message): void
{
    try { $fn(); } catch (LogicException|InvalidArgumentException $e) { return; }
    check(false,$message);
}
check(wp_get_environment_type()==='staging' && (string) get_option('blog_public')==='0','disposable staging only');
check(function_exists('imagecreatetruecolor') && function_exists('imagepng'),'real GD raster support required');
$admin = get_user_by('login','testadmin');
check($admin instanceof WP_User,'disposable administrator');
wp_set_current_user($admin->ID);
$parent = wp_insert_post(['post_type'=>'post','post_status'=>'draft','post_title'=>'Image ingress fixture','post_content'=>'Preserve original'],true);
$published = wp_insert_post(['post_type'=>'post','post_status'=>'publish','post_title'=>'Reject published target'],true);
check(is_int($parent) && $parent>0 && is_int($published) && $published>0,'isolated parents created');
$image = imagecreatetruecolor(320,200);
imagefill($image,0,0,imagecolorallocate($image,32,96,180));
ob_start();
imagepng($image);
$bytes = ob_get_clean();
imagedestroy($image);
check(is_string($bytes),'real fixture bytes');
$manifest = ['filename'=>'site-manager-fixture.png','mime'=>'image/png','sha256'=>hash('sha256',$bytes),
    'bytes'=>strlen($bytes),'width'=>320,'height'=>200];
$encoded = base64_encode($bytes);
$plan = 'sm-plan-'.str_repeat('a',24);
$runtime = new NativeImageIngressRuntime();
$count = static fn()=>count(get_posts(['post_type'=>'attachment','post_parent'=>$parent,'post_status'=>'inherit','numberposts'=>-1,'fields'=>'ids']));
$before = $count();
blocked(fn()=>$runtime->create($parent,$manifest,$encoded,$plan,false),'confirmation required');
blocked(fn()=>$runtime->create($published,$manifest,$encoded,$plan,true),'published parent rejected');
blocked(fn()=>$runtime->create($parent,array_replace($manifest,['sha256'=>str_repeat('0',64)]),$encoded,$plan,true),'incorrect digest rejected');
wp_set_current_user(0);
blocked(fn()=>$runtime->create($parent,$manifest,$encoded,$plan,true),'unauthorized upload rejected');
wp_set_current_user($admin->ID);
check($count()===$before,'invalid attempts created no attachment');
$result = $runtime->create($parent,$manifest,$encoded,$plan,true);
check(($result['verified'] ?? false)===true,'native attachment creation verified');
$id = $result['attachment_id'];
check(is_int($id) && $id>0 && $count()===$before+1,'exactly one native attachment');
$post = get_post($id);
check($post instanceof WP_Post && (int) $post->post_parent===$parent && $post->post_mime_type==='image/png','native attachment identity');
$file = get_attached_file($id,true);
check(is_string($file) && is_file($file) && hash_file('sha256',$file)===$result['stored_sha256'],'stored bytes verified');
$actual = getimagesize($file);
check($actual[0]===320 && $actual[1]===200 && $actual['mime']==='image/png','stored raster dimensions and format');
blocked(fn()=>$runtime->create($parent,$manifest,$encoded,$plan,true),'known plan replay rejected');
check($count()===$before+1,'repeat did not duplicate attachment');
check(get_post_status($parent)==='draft' && get_post_field('post_content',$parent)==='Preserve original' && (int) get_post_thumbnail_id($parent)===0,'draft content and thumbnail unchanged');
echo "DISPOSABLE_WP_NATIVE_IMAGE_INGRESS=PASSED\n";
