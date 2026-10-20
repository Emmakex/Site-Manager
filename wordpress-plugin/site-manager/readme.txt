=== Site Manager ===
Contributors: emmake
Tags: site management, discovery, diagnostics, governance
Requires at least: 6.6
Requires PHP: 8.1
Stable tag: 0.5.2
License: Proprietary

Site Manager discovers the real structure of a WordPress installation and reports what is detected, understood, governable, authorized or blocked.

== Description ==

Site Manager is a platform-neutral control plane. WordPress is its first adapter, not the product boundary.

On activation Site Manager performs a bounded local discovery of the installation, including runtime, routing, active theme, public content types, taxonomies, navigation providers, active plugins and known providers. Detection never grants write access.

The WordPress admin surface follows the configured WordPress/user locale. English is the source language and Spanish (`es_ES`) is bundled.

Version 0.5.2 normalizes `CapabilityGraph` persistence to plain arrays. This fixes the second EMMake `nuevaweb` regression where WordPress serialized nested `Capability` objects even though the graph envelope itself was correct. Dashboard, REST and future MCP consumers now receive one stable persisted representation.

Version 0.5.1 added compatibility with the persisted capability-graph envelope. Version 0.5.0 introduced the generic SEO provider layer and provider registry.

The provider registry currently includes:

* SEO/GEO Theme: semantic native sources; title -> `post_title`, description -> `post_excerpt`; other SEO outputs remain derived.
* Yoast SEO: verified `WPSEO_Meta` API; title, description, canonical and indexability (`default|index|noindex`).
* Rank Math: verified post-meta contract; title, description and canonical. Robots/indexability remains read-only until its array/default contract is separately accepted.
* All in One SEO (AIOSEO): the plugin's own `aioseo_meta_data` REST field through local WordPress `rest_do_request()`; title, description and canonical. No direct custom-table writes are performed.

All writable provider adapters retain Site Manager's guarded lifecycle: plan-only preview, explicit confirmation, stale-target protection, bounded apply, read-back verification and guarded rollback. Unsupported provider surfaces remain blocked instead of being guessed.

Native WordPress content and navigation governance remains available with the same safety model.

== Installation ==

1. Upload the official `site-manager-0.5.2.zip` from Plugins > Add New > Upload Plugin.
2. Activate Site Manager.
3. Open the top-level Site Manager menu in WordPress admin.
4. Review the dashboard and Site Manager > Diagnostics.

== Changelog ==

= 0.5.2 =
* Normalizes `CapabilityGraph::jsonSerialize()` so persisted capabilities are plain arrays rather than nested `Capability` objects.
* Aligns WordPress option persistence, dashboard reads, REST output and future MCP consumers on the same stable structure.
* Replaces the 0.5.1 synthetic dashboard regression fixture with a test built from real `Capability` and `CapabilityGraph` objects.
* Forces discovery refresh on upgrade through the normal version guard so legacy object-backed state is rewritten automatically.

= 0.5.1 =
* Fixes dashboard capability lookup when capabilities are persisted using the `CapabilityGraph` envelope.
* Keeps compatibility with direct capability-list input.
* Makes the SEO ownership row, provider adapter, write mode and governable inputs reflect the same `seo.write` capability used by the capability counters.

= 0.5.0 =
* Adds the generic `SeoProviderAdapter` contract and provider registry.
* Routes SEO planning and execution through the resolved provider adapter rather than theme-specific code.
* Adds guarded Yoast SEO support for title, description, canonical and indexability using Yoast's own `WPSEO_Meta` API.
* Adds guarded Rank Math support for title, description and canonical using verified provider post-meta keys.
* Adds guarded AIOSEO support for title, description and canonical using the plugin's own `aioseo_meta_data` REST contract through local WordPress REST dispatch.
* Preserves provider conflicts as write-blocking states.
* Keeps unknown providers and unverified provider surfaces read/diagnostic only.
* Exposes the resolved provider adapter, write mode and governable SEO inputs in the dashboard/capability metadata.

= 0.4.0 =
* Adds the first verified theme-native semantic SEO writer for `seo-geo-theme`.
* Promotes `seo.write` to governable only when source evidence proves the native-derived contract.
* Maps SEO title to native `post_title` and SEO description to native `post_excerpt` instead of creating parallel metadata.
* Keeps canonical, robots, Open Graph, schema, hreflang and sitemap as derived outputs unless a provider contract proves direct storage.
* Adds plan, explicit confirmation, stale-target protection, verification and rollback for semantic SEO updates.

= 0.3.3 =
* Adds bounded read-only tracing of data sources referenced by SEO-focused theme code.
* Detects native WordPress content, permalink, taxonomy and site-identity API usage.

= 0.3.2 =
* Adds bounded read-only static inspection of the active/parent theme PHP source.
* Adds Site Manager > Diagnostics for detailed provider-contract evidence.

= 0.3.1 =
* Adds bounded local SEO ownership discovery.
* Detects known SEO plugin ownership candidates and reports conflicts.

= 0.3.0 =
* Adds discovery and guarded governance for native WordPress classic menus and block navigation.

= 0.2.0 =
* Adds safe native WordPress content governance for posts and pages.

= 0.1.2 =
* Adds English/Spanish localization for the WordPress dashboard.

= 0.1.1 =
* Fixes dashboard rendering and moves Site Manager into its own top-level WordPress admin menu.

= 0.1.0 =
* First installable WordPress acceptance build.
