=== Site Manager ===
Contributors: emmake
Tags: site management, discovery, diagnostics, governance
Requires at least: 6.6
Requires PHP: 8.1
Stable tag: 0.4.0
License: Proprietary

Site Manager discovers the real structure of a WordPress installation and reports what is detected, understood, governable, authorized or blocked.

== Description ==

Site Manager is a platform-neutral control plane. WordPress is its first adapter, not the product boundary.

On activation Site Manager performs a bounded local discovery of the installation, including runtime, routing, active theme, public content types, taxonomies, navigation providers, active plugins and known providers. Detection never grants write access.

The WordPress admin surface follows the configured WordPress/user locale. English is the source language and Spanish (`es_ES`) is bundled.

Version 0.4.0 completes the first theme-native SEO governance path for the controlled `SEO GEO Starter` family. The 0.3.x diagnostics established that this provider derives SEO output primarily from native WordPress content, permalink, taxonomy and site-identity sources instead of a dedicated per-page SEO metadata store.

Site Manager therefore does not invent parallel SEO fields. For this evidenced provider, `seo.write` becomes a semantic adapter over the real native sources. The first directly governable SEO inputs are title -> `post_title` and description -> `post_excerpt`. Canonical, robots, Open Graph, schema, hreflang and sitemap remain derived outputs and are not silently converted into new metadata fields.

SEO writes use the same guarded lifecycle as native content/navigation governance: plan-only preview, explicit confirmation, target fingerprint protection, bounded apply, verification and rollback. Plans disclose that changing the SEO title changes the native WordPress title and that changing the SEO description changes the native excerpt, which may also be visible on theme surfaces.

The adapter is enabled only when bounded evidence resolves the SEO owner to `seo-geo-theme`, detects native post/permalink sources, finds no SEO-specific post-meta storage, and does not detect ACF/theme-mod/custom-database ownership. Other SEO providers remain blocked until their own adapters are proven.

Native WordPress content and navigation governance remains available with plan, explicit confirmation, verification, stale-target protection and guarded rollback.

== Installation ==

1. Upload the official `site-manager-0.4.0.zip` from Plugins > Add New > Upload Plugin.
2. Activate Site Manager.
3. Open the top-level Site Manager menu in WordPress admin.
4. Review the dashboard and Site Manager > Diagnostics.

== Changelog ==

= 0.4.0 =
* Adds the first verified theme-native semantic SEO writer for `seo-geo-theme`.
* Promotes `seo.write` to governable only when source evidence proves the native-derived contract.
* Maps SEO title to native `post_title` and SEO description to native `post_excerpt` instead of creating parallel metadata.
* Keeps canonical, robots, Open Graph, schema, hreflang and sitemap as derived outputs unless a future provider contract proves direct storage.
* Adds plan, explicit confirmation, stale-target protection, verification and rollback for semantic SEO updates.
* Exposes write mode, writable SEO inputs and derived outputs in capabilities/diagnostics.
* Shows semantic-writer readiness in the Site Manager dashboard.
* Rejects unsupported direct SEO fields such as arbitrary canonical URLs.
* Acceptance fixture: controlled EMMake `nuevaweb` clone before Research Manager parity and independent-site tests.

= 0.3.3 =
* Adds bounded read-only tracing of data sources referenced by SEO-focused theme code.
* Detects native WordPress content, permalink, taxonomy and site-identity API usage.
* Detects post-meta, options, ACF, theme-mod and custom-database source usage without reading values.
* Detects WordPress post-field references and SEO filter-pipeline entries.
* Adds source classification and detailed source evidence to Site Manager > Diagnostics.
* Keeps `seo.write` blocked; the trace cannot grant permissions or execute theme source.

= 0.3.2 =
* Adds bounded read-only static inspection of the active/parent theme PHP source.
* Detects SEO-related post-meta and option key references without reading their values.
* Detects SEO surface hook registrations with callback and relative theme-file evidence.
* Detects canonical, robots, Open Graph, Twitter, JSON-LD/schema, hreflang and sitemap output tokens.
* Adds Site Manager > Diagnostics for detailed provider-contract evidence.
* Never executes scanned source, follows external symlinks, performs network calls or grants write access.
* Keeps `seo.write` blocked until the real storage/output contract is sufficiently evidenced and a verified writer exists.

= 0.3.1 =
* Starts SM-3A with bounded local SEO ownership discovery.
* Resolves the `SEO GEO Starter` theme family as the `seo-geo-theme` theme-native authority without assuming storage keys.
* Detects known SEO plugin ownership candidates (Yoast SEO, Rank Math and AIOSEO).
* Reports multi-owner SEO conflicts instead of selecting a writer silently.
* Inventories bounded SEO-related registered meta keys and hook names as diagnostic evidence.
* Adds a neutral provider ownership declaration filter for future provider adapters.
* Shows SEO owner, owned output surfaces and ownership-probe evidence in the dashboard.
* Keeps `seo.write` blocked until a verified provider-specific write adapter exists.

= 0.3.0 =
* Adds discovery for native WordPress classic menus and block navigation.
* Shows the detected navigation provider and writable target count in the dashboard.
* Makes `navigation.write` governable only when a native actionable target exists.
* Supports bounded updates to existing classic menu items and existing `wp_navigation` records.
* Requires explicit confirmation for navigation apply and rollback.
* Adds target-level stale-plan detection, verification and guarded rollback.
* Automatically refreshes discovery after classic-menu or block-navigation changes.
* Keeps `seo.write` blocked until a real SEO owner/provider adapter is resolved.

= 0.2.0 =
* Adds SM-2A safe native WordPress content governance for posts and pages.
* Makes `content.write` governable while keeping it scope-gated and unauthorized by default.
* Supports title, slug, content, excerpt and publication-state updates only.
* Adds read-only planning with complete pre-change owned-field snapshots.
* Requires explicit confirmation for apply and rollback.
* Rejects stale plans when the target changes after planning.
* Verifies applied state and protects rollback from overwriting later edits.
* Refreshes stored capabilities automatically after Site Manager upgrades.

= 0.1.2 =
* Adds English/Spanish localization for the WordPress dashboard.
* Follows the current WordPress/user locale automatically.
* Keeps machine-facing capability identifiers stable and untranslated.
* Localizes governance-gap explanations and capability-state summaries.

= 0.1.1 =
* Fixes dashboard rendering when the stored platform identity comes from 0.1.0.
* Normalizes persisted discovery data to plain serializable arrays.
* Moves Site Manager out of Tools into its own top-level WordPress admin menu.
* Keeps existing 0.1.0 discovery data compatible and refreshes legacy snapshots automatically.

= 0.1.0 =
* First installable WordPress acceptance build.
* Automatic discovery on activation.
* Structural fingerprint and drift refresh.
* Known provider detection.
* Read/diagnostics capability graph with writes blocked by default.
