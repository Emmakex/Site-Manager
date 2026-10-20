=== Site Manager ===
Contributors: emmake
Tags: site management, discovery, diagnostics, governance
Requires at least: 6.6
Requires PHP: 8.1
Stable tag: 0.3.0
License: Proprietary

Site Manager discovers the real structure of a WordPress installation and reports what is detected, understood, governable, authorized or blocked.

== Description ==

Site Manager is a platform-neutral control plane. WordPress is its first adapter, not the product boundary.

On activation Site Manager performs a bounded local discovery of the installation, including runtime, routing, active theme, public content types, taxonomies, navigation providers, active plugins and known providers. Detection never grants write access.

The WordPress admin surface follows the configured WordPress/user locale. English is the source language and Spanish (`es_ES`) is bundled.

Version 0.3.0 adds SM-2B safe native WordPress navigation governance. Site Manager distinguishes classic WordPress menus from block navigation, exposes navigation writes only when an existing native target is discovered, and keeps every write scope-gated and unauthorized by default.

Navigation planning is read-only, captures a complete pre-change provider-owned snapshot, requires explicit confirmation before apply or rollback, rejects stale targets, verifies the resulting state and protects rollback from overwriting later human edits.

SEO/provider metadata, arbitrary post meta, options, theme files and direct database writes remain outside the WordPress Core adapter.

== Installation ==

1. Upload the official `site-manager-0.3.0.zip` from Plugins > Add New > Upload Plugin.
2. Activate Site Manager.
3. Open the top-level Site Manager menu in WordPress admin.
4. Review the dashboard, detected installation, navigation provider, capability map and missing adapters.

== Changelog ==

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
