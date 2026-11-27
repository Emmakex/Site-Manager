<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

use InvalidArgumentException;
use RuntimeException;

/**
 * Validates the exact image bytes against a reviewed manifest before any upload.
 * No URL fetching, arbitrary paths, filesystem writes, or WordPress mutations.
 */
final class ImageIngressGuard
{
    public const MAX_BYTES = 2_000_000;
    public const MAX_DIMENSION = 4096;
    public const MAX_PIXELS = 12_000_000;
    private const TYPES = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];

    /** @return array{filename:string,mime:string,sha256:string,bytes:int,width:int,height:int} */
    public function manifest(array $input): array
    {
        $keys = ['filename','mime','sha256','bytes','width','height'];
        if (count($input) !== count($keys) || array_diff($keys,array_keys($input))) {
            throw new InvalidArgumentException('Exact image manifest required.');
        }
        $mime = $input['mime'];
        $filename = $input['filename'];
        if (! is_string($mime) || ! isset(self::TYPES[$mime]) || ! is_string($filename)
            || ! preg_match('/^[a-z0-9][a-z0-9_-]{0,99}\.(?:jpg|png|webp)$/D',$filename)
            || pathinfo($filename,PATHINFO_EXTENSION) !== self::TYPES[$mime]
            || ! is_string($input['sha256']) || ! preg_match('/^[a-f0-9]{64}$/D',$input['sha256'])
            || ! is_int($input['bytes']) || $input['bytes'] < 1 || $input['bytes'] > self::MAX_BYTES
            || ! is_int($input['width']) || ! is_int($input['height'])
            || $input['width'] < 1 || $input['height'] < 1
            || $input['width'] > self::MAX_DIMENSION || $input['height'] > self::MAX_DIMENSION
            || $input['width'] * $input['height'] > self::MAX_PIXELS) {
            throw new InvalidArgumentException('Unsupported image manifest.');
        }
        return $input;
    }

    /** Exact canonical Base64 envelope, bounded before allocating decoded bytes. */
    public function decode(array $manifest,string $base64): string
    {
        $manifest = $this->manifest($manifest);
        if (strlen($base64) !== 4 * (int) ceil($manifest['bytes']/3)) {
            throw new InvalidArgumentException('Unexpected encoded image length.');
        }
        $bytes = base64_decode($base64,true);
        if (false === $bytes || base64_encode($bytes) !== $base64) {
            throw new InvalidArgumentException('Invalid canonical image encoding.');
        }
        $this->verify($manifest,$bytes);
        return $bytes;
    }

    public function verify(array $manifest,string $bytes): void
    {
        $manifest = $this->manifest($manifest);
        if (strlen($bytes) !== $manifest['bytes']
            || ! hash_equals($manifest['sha256'],hash('sha256',$bytes))) {
            throw new InvalidArgumentException('Image digest or byte count mismatch.');
        }
        $info = @getimagesizefromstring($bytes);
        if (! is_array($info) || ($info['mime'] ?? '') !== $manifest['mime']
            || $info[0] !== $manifest['width'] || $info[1] !== $manifest['height']) {
            throw new InvalidArgumentException('Actual image format or dimensions mismatch.');
        }
        // Header inspection alone is not validation of a full raster. Require
        // a real decoder. A future ingestion engine must re-encode and verify
        // the resulting file before creating an attachment.
        if (! function_exists('imagecreatefromstring')) {
            throw new RuntimeException('Image decoder unavailable.');
        }
        $image = @imagecreatefromstring($bytes);
        if (false === $image) { throw new InvalidArgumentException('Raster decode failed.'); }
        try {
            if (imagesx($image) !== $manifest['width'] || imagesy($image) !== $manifest['height']) {
                throw new InvalidArgumentException('Decoded raster mismatch.');
            }
        } finally {
            imagedestroy($image);
        }
    }
}
