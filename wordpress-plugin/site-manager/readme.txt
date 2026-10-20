=== Site Manager ===
Contributors: emmake
Tags: site management, discovery, diagnostics, governance
Requires at least: 6.6
Requires PHP: 8.1
Stable tag: 0.3.3
License: Proprietary

Site Manager discovers the real structure of a WordPress installation and reports what is detected, understood, governable, authorized or blocked.

== Description ==

Site Manager is a platform-neutral control plane. WordPress is its first adapter, not the product boundary.

On activation Site Manager performs a bounded local discovery of the installation, including runtime, routing, active theme, public content types, taxonomies, navigation providers, active plugins and known providers. Detection never grants write access.

The WordPress admin surface follows the configured WordPress/user locale. English is the source language and Spanish (`es_ES`) is bundled.

Version 0.3.3 continues SM-3A with a second bounded, read-only SEO inspection layer. In addition to discovering SEO output hooks/tokens and referenced meta/options, Site Manager traces which WordPress data-source APIs, post fields and filter-pipeline entries are referenced by SEO-focused theme code. It never reads content/meta/option values and never executes scanned source.

This evidence is used to decide whether an SEO provider derives metadata from native WordPress content, permalinks, taxonomy/site identity, post meta, options, ACF, theme mods or custom database access before a writer is implemented. The goal is to govern the provider's real source of truth instead of inventing parallel SEO fields.

A dedicated Site Manager > Diagnostics screen shows both the output contract and data-source trace. `seo.write` remains blocked until a provider-specific writer has snapshot, explicit confirmation, stale-target protection, verification and rollback.

The controlled `SEO GEO Starter` family remains recognized as the `seo-geo-theme` theme-native SEO authority with the historically documented output surfaces: title/meta, canonical, robots, Open Graph, schema, hreflang and sitemap.

Native WordPress content and navigation governance from 0.2.0/0.3.0 remains available with plan, explicit confirmation, verification, stale-target protection and guarded rollback.

== Installation ==

1. Upload the official `site-manager-0.3.3.zip` from Plugins > Add New > Upload Plugin.
2. Activate Site Manager.
3. Open the top-level Site Manager menu in WordPress admin.
4. Review the dashboard and Site Manager > Diagnostics.

== Changelog ==

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
