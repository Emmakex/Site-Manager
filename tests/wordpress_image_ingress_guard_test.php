<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use SiteManager\Adapters\WordPress\ImageIngressGuard;

function check(bool $ok,string $message): void
{
    if (! $ok) { throw new RuntimeException($message); }
}
function rejects(callable $fn,string $message): void
{
    try { $fn(); } catch (InvalidArgumentException) { return; }
    throw new RuntimeException($message);
}
$guard = new ImageIngressGuard();
// Small genuine raster generated with GD when available; the core contract
// still exercises metadata, digest, encoding and header rejection without GD.
$bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jf2sAAAAASUVORK5CYII=',true);
if (function_exists('imagecreatetruecolor') && function_exists('imagepng')) {
    $image = imagecreatetruecolor(8,6);
    ob_start();
    imagepng($image);
    $bytes = ob_get_clean();
    imagedestroy($image);
}
check(is_string($bytes),'fixture bytes');
$info = getimagesizefromstring($bytes);
check(is_array($info),'fixture dimensions');
$manifest = [
    'filename'=>'sample-image.png','mime'=>'image/png','sha256'=>hash('sha256',$bytes),
    'bytes'=>strlen($bytes),'width'=>$info[0],'height'=>$info[1],
];
check($guard->manifest($manifest)===$manifest,'exact manifest retained');
foreach ([
    ['filename'=>'../sample.png'],['filename'=>'sample.php.png'],['filename'=>'sampleXpng'],
    ['filename'=>'sample.jpg'],['mime'=>'image/svg+xml'],['mime'=>'image/gif'],
    ['sha256'=>str_repeat('A',64)],['bytes'=>0],['bytes'=>2000001],
    ['width'=>4097],['width'=>4096,'height'=>4096],['height'=>true],
    ['bytes'=>'123'],['url'=>'https://example.invalid/photo.png'],
] as $change) {
    rejects(fn()=>$guard->manifest(array_replace($manifest,$change)),'unsafe manifest accepted');
}
rejects(fn()=>$guard->decode($manifest,base64_encode($bytes).' '),'noncanonical envelope accepted');
rejects(fn()=>$guard->verify(array_replace($manifest,['sha256'=>str_repeat('0',64)]),$bytes),'incorrect digest accepted');
rejects(fn()=>$guard->verify(array_replace($manifest,['width'=>$info[0]+1]),$bytes),'incorrect dimensions accepted');
$fake = 'This is not an image.';
rejects(fn()=>$guard->verify(array_replace($manifest,['bytes'=>strlen($fake),'sha256'=>hash('sha256',$fake)]),$fake),'nonimage accepted');
if (function_exists('imagecreatefromstring')) {
    check($guard->decode($manifest,base64_encode($bytes))===$bytes,'genuine raster decoded and verified');
    echo "IMAGE_INGRESS_RASTER_VALIDATION=PASSED\n";
} else {
    $blocked = false;
    try { $guard->decode($manifest,base64_encode($bytes)); }
    catch (RuntimeException $e) { $blocked = $e->getMessage()==='Image decoder unavailable.'; }
    check($blocked,'missing decoder must fail closed');
    echo "IMAGE_INGRESS_DECODER_REQUIRED=PASSED\n";
}
echo "IMAGE_INGRESS_MANIFEST_CONTRACT=PASSED\n";
