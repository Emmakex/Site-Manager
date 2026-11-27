"""Bounded deterministic WebP derivation before WordPress; original remains in Drive."""
from hashlib import sha256
from io import BytesIO
from pathlib import PurePosixPath
from site_manager_image_artifact import validate_manifest, ImageArtifactError

PROFILES = {
    "hero_webp_v1": (1920, 350000),
    "featured_webp_v1": (1536, 200000),
    "interior_webp_v1": (1200, 150000),
    "card_webp_v1": (800, 100000),
    "thumbnail_webp_v1": (400, 40000),
}
FORMATS = {"image/png":"PNG", "image/jpeg":"JPEG", "image/webp":"WEBP"}

def optimize_image(raw, manifest, profile):
    manifest = validate_manifest(manifest)
    if profile not in PROFILES:
        raise ImageArtifactError("invalid_image_optimization_profile")
    if len(raw) != manifest["bytes"] or sha256(raw).hexdigest() != manifest["sha256"]:
        raise ImageArtifactError("image_optimization_source_mismatch")
    try:
        from PIL import Image, ImageOps, ImageCms
        with Image.open(BytesIO(raw), formats=[FORMATS[manifest["mime"]]]) as source:
            if source.size != (manifest["width"], manifest["height"]) or getattr(source,"n_frames",1) != 1:
                raise ImageArtifactError("image_optimization_dimensions_or_animation")
            source.load()
            image = ImageOps.exif_transpose(source)
            alpha = image.convert("RGBA").getchannel("A") if "A" in image.getbands() or "transparency" in image.info else None
            if image.info.get("icc_profile"):
                image = ImageCms.profileToProfile(image.convert("RGB") if image.mode not in ("RGB","CMYK","L","LAB") else image, ImageCms.ImageCmsProfile(BytesIO(image.info["icc_profile"])), ImageCms.createProfile("sRGB"), outputMode="RGB")
            else:
                image = image.convert("RGB")
            if alpha is not None:
                image.putalpha(alpha)
            edge, budget = PROFILES[profile]
            image.thumbnail((edge,edge), Image.Resampling.LANCZOS)
            image.info.clear()
            for quality in (85,80,75,70,65):
                out = BytesIO()
                image.save(out,format="WEBP",quality=quality,method=4,exact=True)
                encoded = out.getvalue()
                if len(encoded) <= budget:
                    name = PurePosixPath(manifest["filename"]).stem[:89] + "-optimized.webp"
                    derivative = validate_manifest({"filename":name,"mime":"image/webp","bytes":len(encoded),"sha256":sha256(encoded).hexdigest(),"width":image.width,"height":image.height})
                    with Image.open(BytesIO(encoded),formats=["WEBP"]) as check:
                        check.load()
                        if check.size != image.size:
                            raise ImageArtifactError("image_optimization_output_mismatch")
                    return encoded, derivative
    except ImageArtifactError:
        raise
    except Exception:
        raise ImageArtifactError("image_optimization_failed") from None
    raise ImageArtifactError("image_optimization_budget_not_met")
