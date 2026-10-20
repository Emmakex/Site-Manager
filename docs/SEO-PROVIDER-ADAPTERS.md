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

Every writable SEO provider implements `SeoProviderAdapter` and must expose:

- stable provider id;
- stable persisted plan-adapter id;
- snapshot ownership support;
- runtime readiness and real writable inputs;
- planning;
- explicit-confirmation apply;
- verification;
- guarded rollback.

`SeoProviderRegistry` resolves a provider from the discovery snapshot for planning and resolves a persisted plan adapter for apply/verify/rollback. This keeps execution stable after the plan has been created.

## Implementations

### SEO/GEO Theme

Provider id: `seo-geo-theme`

Plan adapter: `seo-geo-theme-native`

Mode: semantic native sources. The proven writable inputs are SEO title and description mapped to the native WordPress sources used by that theme. Other outputs remain derived unless separately proven writable.

### Yoast SEO

Provider id: `yoast-seo`

Plan adapter: `yoast-seo-meta-api`

Mode: Yoast metadata API. The adapter is ready only when Yoast's `WPSEO_Meta::get_value()` and `WPSEO_Meta::set_value()` API is available at runtime.

Initial semantic mapping:

| Site Manager input | Yoast internal field | Behaviour |
| --- | --- | --- |
| `title` | `title` | direct provider override |
| `description` | `metadesc` | direct provider override |
| `canonical` | `canonical` | absolute URL or empty |
| `indexability=default` | `meta-robots-noindex=0` | return to post-type default |
| `indexability=index` | `meta-robots-noindex=2` | explicit index |
| `indexability=noindex` | `meta-robots-noindex=1` | explicit noindex |

Open Graph, schema and sitemap are treated as provider-derived outputs in this first adapter. Site Manager does not invent unsupported direct controls.

## Next providers

Rank Math and AIOSEO must implement the same contract using their own verified storage/runtime semantics. Provider-specific storage details must not be moved into Site Manager Core.
