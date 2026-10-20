=== Site Manager ===
Contributors: emmake
Tags: site management, discovery, diagnostics, governance
Requires at least: 6.6
Requires PHP: 8.1
Stable tag: 0.5.0
License: Proprietary

Site Manager discovers the real structure of a WordPress installation and reports what is detected, understood, governable, authorized or blocked.

== Description ==

Site Manager is a platform-neutral control plane. WordPress is its first adapter, not the product boundary.

On activation Site Manager performs a bounded local discovery of the installation, including runtime, routing, active theme, public content types, taxonomies, navigation providers, active plugins and known providers. Detection never grants write access.

The WordPress admin surface follows the configured WordPress/user locale. English is the source language and Spanish (`es_ES`) is bundled.

Version 0.5.0 introduces the generic SEO provider layer. One stable machine-facing capability (`seo.write` / `seo.update`) is routed to the SEO owner actually discovered on the site. Provider detection alone never grants write access, and multiple SEO owners block writes until the conflict is resolved.

The provider registry currently includes:

* SEO/GEO Theme: semantic native sources; title -> `post_title`, description -> `post_excerpt`; other SEO outputs remain derived.
* Yoast SEO: verified `WPSEO_Meta` API; title, description, canonical and indexability (`default|index|noindex`).
* Rank Math: verified post-meta contract; title, description and canonical. Robots/indexability remains read-only until its array/default contract is separately accepted.
* All in One SEO (AIOSEO): the plugin's own `aioseo_meta_data` REST field through local WordPress `rest_do_request()`; title, description and canonical. No direct custom-table writes are performed.

All writable provider adapters retain Site Manager's guarded lifecycle: plan-only preview, explicit confirmation, stale-target protection, bounded apply, read-back verification and guarded rollback. Unsupported provider surfaces remain blocked instead of being guessed.

Native WordPress content and navigation governance remains available with the same safety model.

== Installation ==

1. Upload the official `site-manager-0.5.0.zip` from Plugins > Add New > Upload Plugin.
2. Activate Site Manager.
3. Open the top-level Site Manager menu in WordPress admin.
4. Review the dashboard and Site Manager > Diagnostics.

== Changelog ==

= 0.5.0 =
* Adds the generic `SeoProviderAdapter` contract and provider registry.
* Routes SEO planning and execution through the resolved provider adapter rather than theme-specific code.
* Adds guarded Yoast SEO support for title, description, canonical and indexability using Yoast's own `WPSEO_Meta` API.
* Adds guarded Rank Math support for title, description and canonical using verified provider post-meta keys.
* Adds guarded AIOSEO support for title, description and canonical using the plugin's own `aioseo_meta_data` REST contract through local WordPress REST dispatch.
* Preserves provider conflicts as write-blocking states.
* Keeps unknown providers and unverified provider surfaces read/diagnostic only.
* Updates the dashboard to use provider-registry readiness, so UI status matches the capability graph for theme and plugin SEO providers.
* Exposes the resolved provider adapter, write mode and governable SEO inputs in the dashboard/capability metadata.

= 0.4.0 =
* Adds the first verified theme-native semantic SEO writer for `seo-geo-theme`.
* Promotes `seo.write` to governable only when source evidence proves the native-derived contract.
* Maps SEO title to native `post_title` and SEO description to native `post_excerpt` instead of creating parallel metadata.
* Keeps canonical, robots, Open Graph, schema, hreflang and sitemap as derived outputs unless a provider contract proves direct storage.
* Adds plan, explicit confirmation, stale-target protection, verification and rollback for semantic SEO updates.
* Exposes write mode, writable SEO inputs and derived outputs in capabilities/diagnostics.

= 0.3.3 =
* Adds bounded read-only tracing of data sources referenced by SEO-focused theme code.
* Detects native WordPress content, permalink, taxonomy and site-identity API usage.
* Detects post-meta, options, ACF, theme-mod and custom-database source usage without reading values.
* Detects WordPress post-field references and SEO filter-pipeline entries.
* Adds source classification and detailed source evidence to Site Manager > Diagnostics.

= 0.3.2 =
* Adds bounded read-only static inspection of the active/parent theme PHP source.
* Detects SEO-related post-meta and option key references without reading their values.
* Detects SEO surface hook registrations with callback and relative theme-file evidence.
* Adds Site Manager > Diagnostics for detailed provider-contract evidence.

= 0.3.1 =
* Adds bounded local SEO ownership discovery.
* Resolves the `SEO GEO Starter` theme family as the `seo-geo-theme` theme-native authority.
* Detects known SEO plugin ownership candidates (Yoast SEO, Rank Math and AIOSEO).
* Reports multi-owner SEO conflicts instead of selecting a writer silently.

= 0.3.0 =
* Adds discovery and guarded governance for native WordPress classic menus and block navigation.
* Requires explicit confirmation, verification and guarded rollback.

= 0.2.0 =
* Adds safe native WordPress content governance for posts and pages.
* Supports title, slug, content, excerpt and publication-state updates.
* Adds plan, explicit confirmation, stale-plan detection, verification and rollback.

= 0.1.2 =
* Adds English/Spanish localization for the WordPress dashboard.
* Keeps machine-facing capability identifiers stable and untranslated.

= 0.1.1 =
* Fixes dashboard rendering and moves Site Manager into its own top-level WordPress admin menu.

= 0.1.0 =
* First installable WordPress acceptance build.
* Automatic discovery on activation.
* Structural fingerprint and drift refresh.
* Read/diagnostics capability graph with writes blocked by default.
