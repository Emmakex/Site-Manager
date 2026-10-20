# SEO provider adapters

Site Manager exposes one machine-facing SEO capability (`seo.write` / `seo.update`) and routes it to the SEO owner discovered in the current WordPress installation.

## Safety model

A provider progresses independently through:

1. detected
2. ownership resolved / understood
3. registered provider adapter
4. provider runtime/API available
5. governable
6. authorized by explicit scope

Detection or installation of a plugin never grants write access. Multiple SEO ownership candidates are a conflict and keep writes blocked.

## Provider contract

Every writable SEO provider implements `SeoProviderAdapter` and exposes a stable provider id, persisted plan-adapter id, ownership support, runtime readiness, real writable inputs, planning, explicit-confirmation apply, verification and guarded rollback.

`SeoProviderRegistry` resolves a provider from the discovery snapshot for planning and resolves a persisted plan adapter for apply/verify/rollback. Provider-specific storage details remain outside Site Manager Core.

## Implementations in 0.5.0

### SEO/GEO Theme

Provider: `seo-geo-theme`  
Plan adapter: `seo-geo-theme-native`  
Write mode: semantic native sources.

| Site Manager input | Provider source |
| --- | --- |
| `title` | `post_title` |
| `description` | `post_excerpt` |

Canonical, robots, Open Graph, schema, hreflang and sitemap remain derived outputs.

### Yoast SEO

Provider: `yoast-seo`  
Plan adapter: `yoast-seo-meta-api`  
Write mode: Yoast `WPSEO_Meta` API.

| Site Manager input | Yoast field |
| --- | --- |
| `title` | `title` |
| `description` | `metadesc` |
| `canonical` | `canonical` |
| `indexability=default` | `meta-robots-noindex=0` |
| `indexability=index` | `meta-robots-noindex=2` |
| `indexability=noindex` | `meta-robots-noindex=1` |

Open Graph, schema and sitemap remain provider-derived in this adapter.

### Rank Math

Provider: `rank-math`  
Plan adapter: `rank-math-post-meta`  
Write mode: verified Rank Math post metadata.

| Site Manager input | Rank Math field |
| --- | --- |
| `title` | `rank_math_title` |
| `description` | `rank_math_description` |
| `canonical` | `rank_math_canonical_url` |

`rank_math_robots` is deliberately not writable in 0.5.0 because its array/default semantics require a separate acceptance contract. Robots, Open Graph, schema and sitemap therefore remain provider-derived/read-only here.

### All in One SEO (AIOSEO)

Provider: `aioseo`  
Plan adapter: `aioseo-rest-meta-data`  
Write mode: AIOSEO's own `aioseo_meta_data` REST contract via local WordPress REST dispatch.

| Site Manager input | AIOSEO field |
| --- | --- |
| `title` | `aioseo_meta_data.title` |
| `description` | `aioseo_meta_data.description` |
| `canonical` | `aioseo_meta_data.canonical_url` |

Site Manager calls WordPress locally with `rest_do_request()`; it makes no network request and performs no direct write to AIOSEO custom tables. AIOSEO's own permissions, validation and sanitization remain authoritative. Robots, social metadata, schema and sitemap remain provider-derived/read-only in this first adapter.

## Unknown providers

An unknown or unsupported SEO provider may still be detected and diagnosed, but `seo.write` remains blocked until a dedicated adapter proves its real storage/runtime contract and passes the Site Manager safety lifecycle.
